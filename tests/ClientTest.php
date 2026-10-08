<?php

declare(strict_types=1);

namespace Hermesi\Laravel\Tests;

use Hermesi\Hermesi as Client;
use Hermesi\Laravel\Facades\Hermesi;
use Hermesi\Subscriber;

final class ClientTest extends TestCase
{
    public function testTheContainerBuildsOneClientFromTheConfig(): void
    {
        $client = $this->app->make(Client::class);

        self::assertInstanceOf(Client::class, $client);
        self::assertSame($client, $this->app->make(Client::class), 'one client for the application');
        self::assertSame($client, $this->app->make('hermesi'), 'and the short alias');
    }

    public function testThePackageConfigIsMergedSoAnUnpublishedAppStillHasTheDefaults(): void
    {
        self::assertSame(30.0, config('hermesi.timeout'));
        self::assertSame([10, 60, 300, 900], config('hermesi.queue.backoff'));
    }

    public function testTheFacadePublishesAnEventThroughTheConfiguredKeyAndAddress(): void
    {
        $result = Hermesi::trigger('order.shipped', 'user_1', ['order_id' => '4821'], idempotencyKey: 'order-4821-shipped');

        self::assertSame('accepted', $result->status);
        $request = $this->server()->last();
        self::assertSame('/v1/events', $request['path']);
        self::assertSame('Bearer '.self::KEY, $request['headers']['authorization']);
        self::assertSame('order-4821-shipped', $request['headers']['idempotency-key']);
    }

    public function testTheMessagesResourceIsReachableThroughTheFacadeAndSendsOneMessage(): void
    {
        $this->server()->setDefault(['status' => 202, 'body' => ['message_id' => 'msg_1', 'status' => 'queued', 'messages' => [['id' => 'msg_1', 'channel' => 'sms', 'status' => 'queued', 'reason' => null]]]]);

        $result = Hermesi::messages()->send('sms', 'user_1', 'otp-code', data: ['code' => '1'], idempotencyKey: 'otp-1');

        self::assertSame(['msg_1', 'queued'], [$result->messageId, $result->status]);
        $request = $this->server()->last();
        self::assertSame(['POST', '/v1/messages', 'otp-1'], [$request['method'], $request['path'], $request['headers']['idempotency-key']]);
        self::assertSame('Bearer '.self::KEY, $request['headers']['authorization']);
    }

    public function testTheSubscribersResourceImportsInBulkThroughTheFacade(): void
    {
        $this->server()->setDefault(['status' => 200, 'body' => ['created' => 1, 'updated' => 1, 'subscribers' => [
            ['external_id' => 'user_1', 'id' => 'sub_1', 'status' => 'created'],
            ['external_id' => 'user_2', 'id' => 'sub_2', 'status' => 'updated'],
        ]]]);

        $result = Hermesi::subscribers()->bulk([['external_id' => 'user_1', 'email' => 'a@example.test'], ['external_id' => 'user_2', 'locale' => null]]);

        $request = $this->server()->last();
        self::assertSame(['POST', '/v1/subscribers/bulk'], [$request['method'], $request['path']]);
        self::assertSame('Bearer '.self::KEY, $request['headers']['authorization']);
        self::assertSame('{"subscribers":[{"external_id":"user_1","email":"a@example.test"},{"external_id":"user_2","locale":null}]}', $request['body']);
        self::assertSame([1, 1], [$result->created, $result->updated]);
        self::assertSame('updated', $result->subscribers[1]->status);
    }

    public function testTheSubscribersResourceWritesAndReadsThroughTheFacade(): void
    {
        $this->server()->setDefault(['status' => 200, 'body' => ['id' => 'sub_1', 'external_id' => 'user_1', 'email' => 'a@example.test', 'locale' => 'fr']]);

        $profile = Hermesi::subscribers()->put('user_1', ['email' => 'a@example.test', 'locale' => 'fr']);
        Hermesi::subscribers()->get('user_1');

        self::assertSame('a@example.test', $profile->email);
        $requests = $this->server()->requests();
        self::assertSame(['PUT', 'GET'], [$requests[0]['method'], $requests[1]['method']]);
        self::assertSame('/v1/subscribers/user_1', $requests[0]['path']);
    }

    public function testTheResourcesAreReachableThroughTheFacadeEvenThoughTheyArePropertiesOnTheClient(): void
    {
        $this->server()->setDefault(['status' => 200, 'body' => ['url' => 'https://h.example/preferences/abc']]);

        self::assertSame('https://h.example/preferences/abc', Hermesi::subscribers()->preferenceLink('user_1')->url);
        self::assertNotEmpty(Hermesi::tokens()->mint('user_1', 'env_1'));

        $this->server()->setDefault(['status' => 202, 'body' => Support\TestServer::ACCEPTED]);
        Hermesi::events()->trigger('order.shipped', new Subscriber('cust_1', email: 'a@example.test'));

        self::assertSame('cust_1', $this->lastRecipient()['external_id']);
    }

    /** @return array<string, mixed> */
    private function lastRecipient(): array
    {
        /** @var array{recipient: array<string, mixed>} $body */
        $body = json_decode($this->server()->last()['body'], true, 512, \JSON_THROW_ON_ERROR);

        return $body['recipient'];
    }

    public function testATokenUsesTheConfiguredEnvironmentUnlessOneIsGiven(): void
    {
        $claims = static function (string $token): array {
            [$payload] = explode('.', $token);
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true, 512, \JSON_THROW_ON_ERROR);

            return $decoded;
        };

        self::assertSame('env_01TEST', $claims(Hermesi::token('user_1'))['env']);
        self::assertSame('env_other', $claims(Hermesi::token('user_1', 'env_other'))['env']);
    }

    public function testATokenWithoutAnyEnvironmentSaysWhatToSet(): void
    {
        config(['hermesi.environment_id' => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HERMESI_ENVIRONMENT_ID');

        Hermesi::token('user_1');
    }

    public function testAMissingKeyIsAClearErrorWhenTheClientIsFirstUsedNotABlankFailure(): void
    {
        config(['hermesi.secret_key' => null]);
        $this->app->forgetInstance(Client::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('apiKey is required');

        $this->app->make(Client::class);
    }

    public function testDdAndTheContainerDumpNeverShowTheKey(): void
    {
        $client = $this->app->make(Client::class);

        // What `dd()` and `dump()` use. A dumper that ignores __debugInfo would print the key from a private property.
        $cloner = new \Symfony\Component\VarDumper\Cloner\VarCloner();
        $dumper = new \Symfony\Component\VarDumper\Dumper\CliDumper();
        $dumped = (string) $dumper->dump($cloner->cloneVar($client), true);
        $dumpedFacadeRoot = (string) $dumper->dump($cloner->cloneVar(Hermesi::events()), true);

        self::assertStringNotContainsString(self::KEY, $dumped);
        self::assertStringNotContainsString('0123456789', $dumped);
        self::assertStringNotContainsString(self::KEY, $dumpedFacadeRoot);
        self::assertStringNotContainsString('0123456789', $dumpedFacadeRoot);
        self::assertStringNotContainsString(self::KEY, print_r($this->app->make(Client::class), true));
    }

    public function testTheConfiguredTimeoutIsTheOneTheClientUses(): void
    {
        config(['hermesi.timeout' => 0.4, 'hermesi.retry.max_retries' => 0]);
        $this->app->forgetInstance(Client::class);
        $this->server()->enqueue(['hang' => true]);
        $started = microtime(true);

        try {
            Hermesi::trigger('order.shipped', 'user_1');
            self::fail('an answer came from a server that never answers');
        } catch (\Hermesi\Exception\ConnectionException) {
            self::assertLessThan(5.0, microtime(true) - $started, 'the 30 second default was used instead of the configured timeout');
        }
    }

    public function testTheConfigCanBePublished(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'hermesi-config', '--force' => true])->assertSuccessful();

        self::assertFileExists(config_path('hermesi.php'));
        @unlink(config_path('hermesi.php'));
    }
}
