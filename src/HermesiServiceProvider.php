<?php

declare(strict_types=1);

namespace Hermesi\Laravel;

use Hermesi\Hermesi;
use Hermesi\RetryPolicy;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class HermesiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hermesi.php', 'hermesi');

        // One client for the application: it holds no per-request state, and building it twice would only repeat the validation.
        $this->app->singleton(Hermesi::class, static function (Application $app): Hermesi {
            $repository = $app->make('config');
            $config = $repository instanceof Repository ? $repository->get('hermesi', []) : [];
            $config = \is_array($config) ? $config : [];
            $retry = \is_array($config['retry'] ?? null) ? $config['retry'] : [];

            return new Hermesi(
                apiKey: self::string($config['secret_key'] ?? null),
                baseUrl: self::string($config['base_url'] ?? null),
                timeout: self::number($config['timeout'] ?? null, 30.0),
                retry: new RetryPolicy(
                    maxRetries: (int) self::number($retry['max_retries'] ?? null, 3.0),
                    baseDelay: self::number($retry['base_delay'] ?? null, 0.5),
                    maxDelay: self::number($retry['max_delay'] ?? null, 8.0),
                    maxRetryAfter: self::number($retry['max_retry_after'] ?? null, 30.0),
                ),
            );
        });
        $this->app->alias(Hermesi::class, 'hermesi');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/hermesi.php' => $this->app->configPath('hermesi.php')], 'hermesi-config');
        }
    }

    private static function number(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
