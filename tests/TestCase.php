<?php

declare(strict_types=1);

namespace Hermesi\Laravel\Tests;

use Hermesi\Laravel\HermesiServiceProvider;
use Hermesi\Laravel\Tests\Support\TestServer;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const KEY = 'hm_sk_test_0123456789';

    protected static ?TestServer $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$server = TestServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
        parent::tearDownAfterClass();
    }

    protected function server(): TestServer
    {
        if (null === self::$server) {
            throw new \LogicException('The test server is not running');
        }

        return self::$server;
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [HermesiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->server()->reset();
        $config = $app['config'];
        $config->set('hermesi.secret_key', self::KEY);
        $config->set('hermesi.base_url', $this->server()->url());
        $config->set('hermesi.environment_id', 'env_01TEST');
        $config->set('hermesi.retry.max_retries', 0);
        $config->set('queue.default', 'sync');
    }
}
