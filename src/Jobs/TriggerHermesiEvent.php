<?php

declare(strict_types=1);

namespace Hermesi\Laravel\Jobs;

use Hermesi\Actor;
use Hermesi\Exception\ApiException;
use Hermesi\Hermesi;
use Hermesi\Subscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Publishes one event to Hermesi from a queue worker.
 *
 * The idempotency key is fixed when the job is created and travels in its payload, so every attempt of this job, and every
 * redelivery of it after a worker died, sends the same key: Hermesi answers a repeat with the original result instead of creating
 * a second event. That is what makes retrying safe, and it is the case the SDK cannot cover on its own, because only the caller
 * knows that two runs are the same thing.
 */
final class TriggerHermesiEvent implements ShouldQueue
{
    // `$holdFor` is the event's `delay` argument. It cannot be called `$delay`: the Queueable trait owns that name (how long the QUEUE
    // holds the job), and a property of the same name in the class is a fatal error.
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    /**
     * @param string|Subscriber|list<string|Subscriber> $recipient
     * @param array<string, mixed>                      $payload
     * @param array<string, mixed>|null                 $override
     */
    public function __construct(
        public readonly string $name,
        public readonly string|Subscriber|array $recipient,
        public readonly array $payload,
        public readonly string $idempotencyKey,
        public readonly ?Actor $actor = null,
        public readonly ?string $holdFor = null,
        public readonly \DateTimeInterface|string|null $sendAt = null,
        public readonly ?array $override = null,
        public readonly ?string $tenant = null,
    ) {
        $queue = config('hermesi.queue');
        $queue = \is_array($queue) ? $queue : [];
        $this->tries = is_numeric($queue['tries'] ?? null) ? (int) $queue['tries'] : 5;
        if (\is_string($queue['connection'] ?? null) && '' !== $queue['connection']) {
            $this->onConnection($queue['connection']);
        }
        if (\is_string($queue['name'] ?? null) && '' !== $queue['name']) {
            $this->onQueue($queue['name']);
        }
    }

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        $backoff = config('hermesi.queue.backoff');

        return \is_array($backoff) && [] !== $backoff ? array_values(array_map(static fn (mixed $seconds): int => is_numeric($seconds) ? (int) $seconds : 0, $backoff)) : [10, 60, 300, 900];
    }

    public function handle(Hermesi $hermesi): void
    {
        try {
            $hermesi->events->trigger(
                $this->name,
                $this->recipient,
                $this->payload,
                $this->idempotencyKey,
                $this->actor,
                $this->holdFor,
                $this->sendAt,
                $this->override,
                $this->tenant,
            );
        } catch (ApiException $e) {
            if (!$e->isRetryable()) {
                // A bad key, an unknown subscriber, a malformed event: waiting will not change the answer, so do not wait.
                $this->fail($e);

                return;
            }
            if (null !== $e->retryAfter) {
                // The server said when to come back, and it was longer than the SDK was willing to sleep inside a worker.
                $this->release((int) ceil($e->retryAfter));

                return;
            }
            throw $e;
        }
    }
}
