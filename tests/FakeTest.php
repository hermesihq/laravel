<?php

declare(strict_types=1);

namespace Hermesi\Laravel\Tests;

use Hermesi\Exception\SimulationException;
use Hermesi\Hermesi as Client;
use Hermesi\Laravel\Facades\Hermesi;
use Hermesi\SimulatedCall;
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

    public function testRecordsAMessageSentThroughTheFacadeAndCanLookInsideIt(): void
    {
        Hermesi::fake();

        $result = Hermesi::messages()->send('sms', 'user_1', 'otp-code', data: ['code' => '480219'], idempotencyKey: 'otp-1');

        self::assertSame('simulated', $result->status);
        self::assertSame([], $this->server()->requests(), 'no request was made');
        Hermesi::assertMessageSent('sms');
        Hermesi::assertMessageSent('sms', static fn (SimulatedCall $c): bool => ['code' => '480219'] === ($c->body['data'] ?? null) && 'otp-1' === $c->idempotencyKey);
        Hermesi::assertMessageNotSent('email');
        Hermesi::assertMessageNotSent('sms', static fn (SimulatedCall $c): bool => 'other' === ($c->body['template'] ?? null));
    }

    public function testMessageAssertionsFailWhenTheyShould(): void
    {
        Hermesi::fake();
        Hermesi::messages()->send('sms', 'user_1', 'otp-code');

        foreach ([
            static fn () => Hermesi::assertMessageSent('email'),
            static fn () => Hermesi::assertMessageSent('sms', static fn (): bool => false),
            static fn () => Hermesi::assertMessageNotSent('sms'),
            static fn () => Hermesi::assertNoMessageSent(),
            static fn () => Hermesi::assertSubscriberWritten('user_1'),
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

    public function testAssertNoMessageSentPassesWhenNoneWasAndAnEventIsNotAMessage(): void
    {
        Hermesi::fake();
        Hermesi::trigger('order.shipped', 'user_1');

        Hermesi::assertNoMessageSent();
    }

    public function testRecordsASubscriberWrite(): void
    {
        Hermesi::fake();

        Hermesi::subscribers()->put('team/42', ['locale' => 'fr']);
        Hermesi::subscribers()->patch('user_2', ['email' => null]);

        Hermesi::assertSubscriberWritten('team/42');
        Hermesi::assertSubscriberWritten('team/42', static fn (SimulatedCall $c): bool => ['locale' => 'fr'] === $c->body);
        Hermesi::assertSubscriberWritten('user_2', static fn (SimulatedCall $c): bool => 'PATCH' === $c->method && ['email' => null] === $c->body);
        $this->expectException(AssertionFailedError::class);
        Hermesi::assertSubscriberWritten('team/42', static fn (): bool => false);
    }

    public function testRecordsABulkImportAndCanLookInsideIt(): void
    {
        Hermesi::fake();

        $result = Hermesi::subscribers()->bulk([['external_id' => 'a', 'locale' => 'fr'], ['external_id' => 'b', 'phone_e164' => null]]);

        self::assertSame([2, 0], [$result->created, $result->updated]);
        self::assertSame([], $this->server()->requests(), 'no request was made');
        Hermesi::assertSubscribersImported();
        Hermesi::assertSubscribersImported(static fn (SimulatedCall $c): bool => [['external_id' => 'a', 'locale' => 'fr'], ['external_id' => 'b', 'phone_e164' => null]] === ($c->body['subscribers'] ?? null));
        $this->expectException(AssertionFailedError::class);
        Hermesi::assertSubscribersImported(static fn (): bool => false);
    }

    public function testABulkImportAssertionFailsWhenNoneHappenedAndAMessageIsNotOne(): void
    {
        Hermesi::fake();
        Hermesi::messages()->send('sms', 'user_1', 'otp-code');
        Hermesi::subscribers()->put('user_1', ['locale' => 'fr']);

        $this->expectException(AssertionFailedError::class);

        Hermesi::assertSubscribersImported();
    }

    public function testAReadFromTheFakeThrowsInsteadOfInventingAnAnswer(): void
    {
        Hermesi::fake();

        $this->expectException(SimulationException::class);

        Hermesi::subscribers()->get('user_1');
    }

    public function testMessageAssertionsNeedAFakeToo(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('fake()');

        Hermesi::assertNoMessageSent();
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
