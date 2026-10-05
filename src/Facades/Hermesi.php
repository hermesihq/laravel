<?php

declare(strict_types=1);

namespace Hermesi\Laravel\Facades;

use Hermesi\Actor;
use Hermesi\EventResult;
use Hermesi\Events;
use Hermesi\Hermesi as Client;
use Hermesi\Laravel\Jobs\TriggerHermesiEvent;
use Hermesi\SimulatedEvent;
use Hermesi\Subscriber;
use Hermesi\Subscribers;
use Hermesi\Tokens;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Assert;

/**
 * @method static bool                 isSimulating()
 * @method static list<SimulatedEvent> simulated()
 *
 * @see Client
 */
final class Hermesi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }

    /**
     * The client's resources are properties, which a facade cannot proxy, so each has a method here:
     * `Hermesi::events()->trigger(...)`, `Hermesi::subscribers()->preferenceLink(...)`, `Hermesi::tokens()->mint(...)`.
     */
    public static function events(): Events
    {
        return self::client()->events;
    }

    public static function subscribers(): Subscribers
    {
        return self::client()->subscribers;
    }

    public static function tokens(): Tokens
    {
        return self::client()->tokens;
    }

    /**
     * Publish an event now, and wait for Hermesi's answer. See Events::trigger(); use `dispatch()` to do it from a queue worker.
     *
     * @param string|Subscriber|list<string|Subscriber> $recipient
     * @param array<string, mixed>                      $payload
     * @param array<string, mixed>|null                 $override
     */
    public static function trigger(
        string $name,
        string|Subscriber|array $recipient,
        array $payload = [],
        ?string $idempotencyKey = null,
        ?Actor $actor = null,
        ?string $delay = null,
        \DateTimeInterface|string|null $sendAt = null,
        ?array $override = null,
        ?string $tenant = null,
    ): EventResult {
        return self::client()->events->trigger($name, $recipient, $payload, $idempotencyKey, $actor, $delay, $sendAt, $override, $tenant);
    }

    /**
     * A subscriber token for `$externalId`, in the environment set in `config('hermesi.environment_id')` unless you pass one.
     * Valid for an hour at most. Minted locally: no request is made.
     */
    public static function token(string $externalId, ?string $environmentId = null, int $ttlSeconds = 3600): string
    {
        $environment = $environmentId ?? config('hermesi.environment_id');
        if (!\is_string($environment) || '' === $environment) {
            throw new \InvalidArgumentException('No environment id: pass one, or set HERMESI_ENVIRONMENT_ID (config hermesi.environment_id).');
        }

        return self::client()->tokens->mint($externalId, $environment, $ttlSeconds);
    }

    private static function client(): Client
    {
        $root = static::getFacadeRoot();
        if (!$root instanceof Client) {
            throw new \LogicException('The Hermesi client is not bound.');
        }

        return $root;
    }

    /**
     * Publish an event from a queue worker, so that the request does not wait for Hermesi and a Hermesi outage does not fail it.
     *
     * The event is validated here, in the request, exactly as a real call would be: a payload that cannot be sent throws now and
     * not in a worker an hour later. Its idempotency key is fixed here too, so a retried or redelivered job cannot send twice; pass
     * your own when the thing that happened has an identity (`order-4821-shipped`).
     *
     * @param string|Subscriber|list<string|Subscriber> $recipient
     * @param array<string, mixed>                      $payload
     * @param array<string, mixed>|null                 $override
     */
    public static function dispatch(
        string $name,
        string|Subscriber|array $recipient,
        array $payload = [],
        ?string $idempotencyKey = null,
        ?Actor $actor = null,
        ?string $delay = null,
        \DateTimeInterface|string|null $sendAt = null,
        ?array $override = null,
        ?string $tenant = null,
    ): PendingDispatch {
        // The same checks a real call makes, against a client that sends nothing.
        (new Client(simulate: true))->events->trigger($name, $recipient, $payload, $idempotencyKey, $actor, $delay, $sendAt, $override, $tenant);

        return TriggerHermesiEvent::dispatch(
            $name,
            $recipient,
            $payload,
            null === $idempotencyKey || '' === $idempotencyKey ? (string) \Illuminate\Support\Str::uuid() : $idempotencyKey,
            $actor,
            $delay,
            $sendAt,
            $override,
            $tenant,
        );
    }

    /**
     * Replace the client with one that sends nothing and records what it would have sent. For tests:
     *
     *     Hermesi::fake();
     *     $this->post('/orders/1/ship');
     *     Hermesi::assertTriggered('order.shipped', fn (SimulatedEvent $e) => $e->payload['order_id'] === '1');
     *
     * An event published with `dispatch()` is recorded when its job runs: with the sync queue driver that is at once, and with
     * `Queue::fake()` assert on `TriggerHermesiEvent` instead.
     */
    public static function fake(): Client
    {
        $fake = new Client(simulate: true);
        // `swap()` also binds the instance in the container, so code that type-hints the client gets the fake too.
        static::swap($fake);

        return $fake;
    }

    /**
     * @param (callable(SimulatedEvent): bool)|null $callback
     */
    public static function assertTriggered(string $name, ?callable $callback = null): void
    {
        Assert::assertNotEmpty(self::matching($name, $callback), \sprintf('The expected event [%s] was not triggered.', $name));
    }

    /**
     * @param (callable(SimulatedEvent): bool)|null $callback
     */
    public static function assertNotTriggered(string $name, ?callable $callback = null): void
    {
        Assert::assertEmpty(self::matching($name, $callback), \sprintf('The unexpected event [%s] was triggered.', $name));
    }

    /**
     * @param (callable(SimulatedEvent): bool)|null $callback
     */
    public static function assertTriggeredTimes(string $name, int $times, ?callable $callback = null): void
    {
        $count = \count(self::matching($name, $callback));
        Assert::assertSame($times, $count, \sprintf('The event [%s] was triggered %d times, expected %d.', $name, $count, $times));
    }

    public static function assertNothingTriggered(): void
    {
        $names = array_map(static fn (SimulatedEvent $e): string => $e->name, self::recorded());
        Assert::assertEmpty($names, 'Events were triggered unexpectedly: '.implode(', ', $names));
    }

    /**
     * @param (callable(SimulatedEvent): bool)|null $callback
     *
     * @return list<SimulatedEvent>
     */
    private static function matching(string $name, ?callable $callback): array
    {
        return array_values(array_filter(
            self::recorded(),
            static fn (SimulatedEvent $e): bool => $e->name === $name && (null === $callback || true === $callback($e)),
        ));
    }

    /** @return list<SimulatedEvent> */
    private static function recorded(): array
    {
        $root = static::getFacadeRoot();
        if (!$root instanceof Client || !$root->isSimulating()) {
            throw new \LogicException('Hermesi::fake() must be called before asserting.');
        }

        return $root->simulated();
    }
}
