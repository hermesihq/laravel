<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Secret key and address
    |--------------------------------------------------------------------------
    |
    | Your SECRET key (hm_sk_...), server side only, and where Hermesi runs. Never put the key in a file that is committed: set
    | HERMESI_SECRET_KEY in your environment.
    |
    */
    'secret_key' => env('HERMESI_SECRET_KEY'),
    'base_url' => env('HERMESI_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Environment id
    |--------------------------------------------------------------------------
    |
    | The env_... id shown in the dashboard. It is not part of the key, and minting a subscriber token needs it:
    | Hermesi::tokens()->mint($userId) reads it from here.
    |
    */
    'environment_id' => env('HERMESI_ENVIRONMENT_ID'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | `timeout` is in seconds and applies to the HTTP client the package builds when Guzzle or Symfony's HttpClient is installed.
    | `retry` is the SDK's own inline retry, for a dropped connection or a 5xx: a few attempts within the same request, in seconds.
    | A longer outage is the queued job's business (see `queue`).
    |
    */
    'timeout' => (float) env('HERMESI_TIMEOUT', 30),
    'retry' => [
        'max_retries' => (int) env('HERMESI_MAX_RETRIES', 3),
        'base_delay' => 0.5,
        'max_delay' => 8.0,
        'max_retry_after' => 30.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Used by Hermesi::dispatch(), which publishes an event from a queue worker so that a request does not wait for Hermesi. `null`
    | means the application's default connection and queue. `tries` and `backoff` (seconds) apply when Hermesi could not be reached or
    | answered with an error that waiting can fix; an error that retrying cannot fix (a bad key, a malformed event) fails the job at once.
    |
    */
    'queue' => [
        'connection' => env('HERMESI_QUEUE_CONNECTION'),
        'name' => env('HERMESI_QUEUE'),
        'tries' => 5,
        'backoff' => [10, 60, 300, 900],
    ],
];
