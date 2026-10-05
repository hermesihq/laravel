<?php

declare(strict_types=1);

namespace Hermesi\Laravel\Tests;

use Hermesi\Actor;
use Hermesi\Laravel\Facades\Hermesi;
use Hermesi\Laravel\Jobs\TriggerHermesiEvent;
use Hermesi\Laravel\Tests\Support\Status;
use Hermesi\Laravel\Tests\Support\TestServer;
use Hermesi\Subscriber;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Queue;
use Mockery;

final class DispatchTest extends TestCase
{
    public function testQueuesAJobWithTheEventAndAKeyFixedAtDispatch(): void
    {
        Queue::fake();

        Hermesi::dispatch('order.shipped', 'user_1', ['order_id' => '4821']);

        Queue::assertPushed(TriggerHermesiEvent::class, static function (TriggerHermesiEvent $job): bool {
            self::assertSame('order.shipped', $job->name);
            self::assertSame('user_1', $job->recipient);
            self::assertSame(['order_id' => '4821'], $job->payload);
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $job->idempotencyKey);

            return true;
        });
    }

    public function testKeepsTheCallersIdempotencyKeyAndGivesTwoDispatchesTwoKeys(): void
    {
        Queue::fake();

        Hermesi::dispatch('order.shipped', 'user_1', idempotencyKey: 'order-4821-shipped');
        Hermesi::dispatch('order.shipped', 'user_1');
        Hermesi::dispatch('order.shipped', 'user_1');

        $keys = [];
        Queue::assertPushed(TriggerHermesiEvent::class, static function (TriggerHermesiEvent $job) use (&$keys): bool {
            $keys[] = $job->idempotencyKey;

            return true;
        });
        self::assertCount(3, $keys);
        self::assertContains('order-4821-shipped', $keys);
        self::assertCount(3, array_unique($keys));
    }

    public function testValidatesInTheRequestSoABadPayloadFailsNowAndNotInAWorkerLater(): void
    {
        Queue::fake();

        foreach ([
            static fn () => Hermesi::dispatch('order.shipped', 'user_1', ['total' => \NAN]),
            static fn () => Hermesi::dispatch('', 'user_1'),
            static fn () => Hermesi::dispatch('order.shipped', 'user_1', ['a', 'b']), // @phpstan-ignore argument.type
        ] as $call) {
            try {
                $call();
                self::fail('queued');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Queue::assertNothingPushed();
    }

    public function testTheQueueAndConnectionComeFromTheConfig(): void
    {
        config(['hermesi.queue.connection' => 'redis', 'hermesi.queue.name' => 'notifications']);
        Queue::fake();

        Hermesi::dispatch('order.shipped', 'user_1');

        Queue::assertPushedOn('notifications', TriggerHermesiEvent::class);
        Queue::assertPushed(TriggerHermesiEvent::class, static fn (TriggerHermesiEvent $job): bool => 'redis' === $job->connection);
    }

    public function testCarriesEveryArgumentThroughQueueSerialisation(): void
    {
        $job = new TriggerHermesiEvent(
            'order.shipped',
            [new Subscriber('cust_1', email: 'a@example.test', data: ['plan' => 'pro']), 'user_2'],
            ['at' => new \DateTimeImmutable('2026-10-04T12:00:00+00:00'), 'status' => Status::Shipped],
            'k1',
            new Actor(externalId: 'u9', name: 'Ada'),
            '5m',
            new \DateTimeImmutable('2026-12-01T09:30:00+01:00'),
            ['email' => ['subject' => 'Hi']],
            'acme',
        );

        /** @var TriggerHermesiEvent $restored */
        $restored = unserialize(serialize($job));

        self::assertEquals($job->recipient, $restored->recipient);
        self::assertEquals($job->payload, $restored->payload);
        self::assertSame('k1', $restored->idempotencyKey);
        self::assertEquals($job->actor, $restored->actor);
        self::assertEquals($job->sendAt, $restored->sendAt);
        self::assertSame('acme', $restored->tenant);
    }

    public function testRunningTheJobPublishesTheEventWithTheKeyItWasGiven(): void
    {
        Hermesi::dispatch('order.shipped', 'user_1', ['order_id' => '4821'], idempotencyKey: 'order-4821-shipped');

        $request = $this->server()->last();
        self::assertSame('/v1/events', $request['path']);
        self::assertSame('order-4821-shipped', $request['headers']['idempotency-key']);
        self::assertSame('Bearer '.self::KEY, $request['headers']['authorization']);
    }

    public function testEveryAttemptAndRedeliveryOfOneJobSendsTheSameKeySoHermesiCannotCreateTwoEvents(): void
    {
        $job = new TriggerHermesiEvent('order.shipped', 'user_1', ['order_id' => '1'], 'fixed-key');
        $redelivered = unserialize(serialize($job));
        self::assertInstanceOf(TriggerHermesiEvent::class, $redelivered);

        $client = $this->app->make(\Hermesi\Hermesi::class);
        $job->handle($client);
        $job->handle($client);
        $redelivered->handle($client);

        $keys = array_map(static fn (array $r): string => $r['headers']['idempotency-key'], $this->server()->requests());
        self::assertSame(['fixed-key', 'fixed-key', 'fixed-key'], $keys);
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function runWith(array $answer): QueueJob&Mockery\MockInterface
    {
        $this->server()->enqueue($answer);
        /** @var QueueJob&Mockery\MockInterface $queueJob */
        $queueJob = \Mockery::mock(QueueJob::class);
        $job = new TriggerHermesiEvent('order.shipped', 'user_1', [], 'k');
        $job->setJob($queueJob);
        $queueJob->shouldReceive('isDeleted')->andReturn(false);
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('hasFailed')->andReturn(false);

        $this->jobUnderTest = $job;

        return $queueJob;
    }

    private ?TriggerHermesiEvent $jobUnderTest = null;

    public function testAnErrorThatWaitingCannotFixFailsTheJobAtOnceInsteadOfRetryingFiveTimes(): void
    {
        foreach ([400 => 'validation_error', 401 => 'invalid_api_key', 404 => 'subscriber_not_found', 422 => 'validation_error'] as $status => $code) {
            $queueJob = $this->runWith(['status' => $status, 'body' => TestServer::error($code)]);
            $queueJob->shouldReceive('fail')->once();
            $queueJob->shouldReceive('release')->never();

            $this->jobUnderTest?->handle($this->app->make(\Hermesi\Hermesi::class));

            $this->addToAssertionCount(1);
        }
    }

    public function testAServerErrorIsThrownSoTheQueueRetriesItWithItsBackoff(): void
    {
        $queueJob = $this->runWith(['status' => 503, 'body' => TestServer::error('unavailable')]);
        $queueJob->shouldReceive('fail')->never();
        $queueJob->shouldReceive('release')->never();

        $this->expectException(\Hermesi\Exception\ServerException::class);

        $this->jobUnderTest?->handle($this->app->make(\Hermesi\Hermesi::class));
    }

    public function testARateLimitWithALongRetryAfterReleasesTheJobForThatLongNotForABlindBackoff(): void
    {
        $queueJob = $this->runWith(['status' => 429, 'body' => TestServer::error('rate_limited'), 'headers' => ['Retry-After' => '120']]);
        $queueJob->shouldReceive('fail')->never();
        $queueJob->shouldReceive('release')->once()->with(120);

        $this->jobUnderTest?->handle($this->app->make(\Hermesi\Hermesi::class));

        $this->addToAssertionCount(1);
    }

    public function testAnUnusableQueueConfigFallsBackToSensibleDefaultsInsteadOfRetryingForever(): void
    {
        foreach ([['tries' => 'many', 'backoff' => 'soon'], ['tries' => null, 'backoff' => []]] as $queue) {
            config(['hermesi.queue.tries' => $queue['tries'], 'hermesi.queue.backoff' => $queue['backoff']]);

            $job = new TriggerHermesiEvent('a.b', 'u', [], 'k');

            self::assertSame(5, $job->tries);
            self::assertSame([10, 60, 300, 900], $job->backoff());
        }
    }

    public function testTriesAndBackoffComeFromTheConfig(): void
    {
        config(['hermesi.queue.tries' => 3, 'hermesi.queue.backoff' => [5, 25]]);

        $job = new TriggerHermesiEvent('a.b', 'u', [], 'k');

        self::assertSame(3, $job->tries);
        self::assertSame([5, 25], $job->backoff());
    }
}
