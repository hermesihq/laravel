<?php

declare(strict_types=1);

namespace Hermesi\Laravel\Tests;

use Hermesi\Hermesi as Client;
use Hermesi\Laravel\Facades\Hermesi;
use Hermesi\SimulatedEvent;
use PHPUnit\Framework\AssertionFailedError;

final class FakeTest extends TestCase
{
    public function testSendsNothingAndRecordsWhatItWouldHaveSent(): void
    {
        Hermesi::fake();

        $result = Hermesi::trigger('order.shipped', 'user_1', ['order_id' => '4821']);

        self::assertSame('simulated', $result->status);
        self::assertSame([], $this->server()->requests(), 'no request was made');
        Hermesi::assertTriggered('order.shipped');
    }

    public function testEveryPartOfTheApplicationSeesTheFakeNotJustTheFacade(): void
    {
        $fake = Hermesi::fake();

        // Code that type-hints the client gets the same fake, so a service under test cannot reach the network by another door.
        self::assertSame($fake, $this->app->make(Client::class));
        $this->app->make(Client::class)->events->trigger('a.b', 'u');
        Hermesi::assertTriggered('a.b');
    }

    public function testAssertTriggeredCanLookInsideTheEvent(): void
    {
        Hermesi::fake();
        Hermesi::trigger('order.shipped', 'user_1', ['order_id' => '1']);
        Hermesi::trigger('order.shipped', 'user_2', ['order_id' => '2']);

        Hermesi::assertTriggered('order.shipped', static fn (SimulatedEvent $e): bool => '2' === $e->payload['order_id']);
        Hermesi::assertNotTriggered('order.shipped', static fn (SimulatedEvent $e): bool => '3' === $e->payload['order_id']);
        Hermesi::assertTriggeredTimes('order.shipped', 2);
        Hermesi::assertTriggeredTimes('order.shipped', 1, static fn (SimulatedEvent $e): bool => 'user_1' === $e->recipient);
    }

    public function testAssertionsFailWhenTheyShould(): void
    {
        Hermesi::fake();
        Hermesi::trigger('order.shipped', 'user_1', ['order_id' => '1']);

        foreach ([
            static fn () => Hermesi::assertTriggered('order.cancelled'),
            static fn () => Hermesi::assertTriggered('order.shipped', static fn (): bool => false),
            static fn () => Hermesi::assertNotTriggered('order.shipped'),
            static fn () => Hermesi::assertTriggeredTimes('order.shipped', 2),
            static fn () => Hermesi::assertNothingTriggered(),
        ] as $assertion) {
            try {
                $assertion();
                self::fail('the assertion passed when it should have failed');
            } catch (AssertionFailedError $e) {
                $this->addToAssertionCount(1);
                self::assertNotSame('the assertion passed when it should have failed', $e->getMessage());
            }
        }
    }

    public function testAssertNothingTriggeredPassesWhenNothingWas(): void
    {
        Hermesi::fake();

        Hermesi::assertNothingTriggered();
    }

    public function testAssertingWithoutAFakeIsAnErrorNotAFalsePass(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('fake()');

        Hermesi::assertNothingTriggered();
    }

    public function testAPayloadThatCouldNotBeSentFailsInTheTestAsItWouldInProduction(): void
    {
        Hermesi::fake();

        $this->expectException(\InvalidArgumentException::class);

        Hermesi::trigger('order.shipped', 'user_1', ['total' => \NAN]);
    }
}
