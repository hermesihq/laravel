# hermesihq/laravel

[Hermesi](https://github.com/hermesihq) for Laravel: a facade, a test fake and a queued job, on top of
[`hermesihq/hermesi`](https://github.com/hermesihq/php). It decides nothing about notifications; it makes the SDK feel native.

- **A facade and a container binding**, configured from `config/hermesi.php` and your `.env`.
- **`Hermesi::dispatch()`**: publish an event from a queue worker, so a request does not wait for Hermesi and a Hermesi outage does not fail it.
  Its idempotency key is fixed when you dispatch, so a retried or redelivered job **cannot send a notification twice**.
- **`Hermesi::fake()`** with assertions, in the style of `Notification::fake()`, for events, direct messages and subscriber writes.
- Laravel 10, 11, 12 and 13, on PHP 8.1 and later.

## Install

```
composer require hermesihq/laravel
```

The service provider is discovered. Set your **secret** key and your Hermesi address in `.env`:

```
HERMESI_SECRET_KEY=hm_sk_...
HERMESI_BASE_URL=https://your-hermesi-host
HERMESI_ENVIRONMENT_ID=env_01...
```

`HERMESI_ENVIRONMENT_ID` (the `env_...` id shown in the dashboard) is only needed to mint subscriber tokens. To change the defaults
(timeout, retries, queue), publish the config:

```
php artisan vendor:publish --tag=hermesi-config
```

The package needs a PSR-18 HTTP client. A Laravel application already has Guzzle, which is used.

## Publish an event

```php
use Hermesi\Laravel\Facades\Hermesi;

Hermesi::trigger('order.shipped', $user->id, ['order_id' => $order->id], idempotencyKey: "order-{$order->id}-shipped");
```

That waits for Hermesi's answer. `202` means the event is recorded and queued; nothing has been delivered yet, so watch the Activity Log in the
dashboard. The arguments are the SDK's: see [`hermesihq/hermesi`](https://github.com/hermesihq/php) for recipients, `Subscriber`, `delay`,
`sendAt`, `override`, `tenant`, and for what is refused before anything is sent.

The client is also in the container, so you can type-hint it:

```php
public function __construct(private \Hermesi\Hermesi $hermesi) {}
```

`Hermesi::events()`, `Hermesi::subscribers()` and `Hermesi::tokens()` give the SDK's resources.

## From a queue: `Hermesi::dispatch()`

```php
Hermesi::dispatch('order.shipped', $user->id, ['order_id' => $order->id]);
```

The arguments are the same. What differs:

- **The request does not wait**, and it does not fail if Hermesi is down. A worker publishes the event and retries with a backoff.
- **It is validated now.** A payload that cannot be sent (`NAN`, an object that is not JSON, a list where an object is needed) throws
  where you dispatch, not in a worker an hour later.
- **It cannot send twice.** The idempotency key is generated when you dispatch and travels in the job. Every attempt of the job, and every
  redelivery after a worker died, sends the same key, and Hermesi answers a repeat with the original event instead of creating a second one.
  If the thing that happened has an identity, pass your own key (`order-4821-shipped`): then even two *dispatches* collapse into one.
- **Errors that waiting cannot fix fail the job at once** instead of being retried five times: a wrong key, an unknown subscriber, a malformed
  event. A `429` with a long `Retry-After` releases the job for exactly that long. A dropped connection or a `5xx` is retried with the backoff in
  `config/hermesi.php` (10 s, 1 min, 5 min, 15 min, then the job is failed).

Two layers of retry, on purpose: the SDK retries a few times inside the same attempt for a blip, and the queue retries for an outage.

Set the queue in `config/hermesi.php` (`HERMESI_QUEUE_CONNECTION`, `HERMESI_QUEUE`). `Hermesi::dispatch()` returns a `PendingDispatch`, so
`->delay(...)`, `->onQueue(...)` and the rest of Laravel's job API work.

## Subscribers, event read-back and one-off messages

The SDK's other resources are reachable the same way, as `Hermesi::events()`, `subscribers()` and `messages()`:

```php
// Keep Hermesi in step with your users table (a key you give is set, null clears it, a key you leave out is left alone).
Hermesi::subscribers()->put((string) $user->id, ['email' => $user->email, 'locale' => $user->locale, 'phone_e164' => null]);
Hermesi::subscribers()->registerChannel((string) $user->id, 'push', $deviceToken, ['platform' => 'android']);
Hermesi::subscribers()->updatePreferences((string) $user->id, categories: ['marketing' => ['email' => false]]);
Hermesi::subscribers()->delete((string) $user->id); // on account deletion

// What became of an event.
Hermesi::events()->get($result->eventId)->messages();

// The channel is a requirement (an OTP that must be an SMS): one message through one published template.
Hermesi::messages()->send('sms', (string) $user->id, 'otp-code', data: ['code' => $code], category: 'security', idempotencyKey: "otp-{$user->id}-{$challenge->id}");
```

See the [SDK's README](https://github.com/hermesihq/php#keep-your-subscribers-in-sync) for what each does, and read what it says about idempotency
keys before sending a message: pass your own when your code can run twice. These calls are made when you make them, in the request. Queue them
yourself (a job of your own) if you do not want a request to wait for Hermesi.

## Subscriber tokens

A browser or an app talks to Hermesi's client API as one subscriber, with a token minted on your server:

```php
Route::get('/hermesi-token', fn () => ['token' => Hermesi::token(auth()->id())])->middleware('auth');
```

Valid for an hour at most; minted locally, with no request. The environment comes from `HERMESI_ENVIRONMENT_ID` unless you pass one:
`Hermesi::token($id, 'env_01...')`. Hand the token to your frontend (`@hermesihq/js`, `@hermesihq/react`).

## Testing

```php
use Hermesi\Laravel\Facades\Hermesi;
use Hermesi\SimulatedEvent;

public function test_shipping_an_order_notifies_the_customer(): void
{
    Hermesi::fake();

    $this->post('/orders/1/ship')->assertOk();

    Hermesi::assertTriggered('order.shipped', fn (SimulatedEvent $e) => $e->payload['order_id'] === '1');
    Hermesi::assertNotTriggered('order.cancelled');
    Hermesi::assertTriggeredTimes('order.shipped', 1);
    Hermesi::assertNothingTriggered(); // when it should not have
}
```

Direct messages and subscriber writes are recorded too, and have their own assertions. The callback gets a `Hermesi\SimulatedCall`, whose
`body` is the JSON that would have been sent:

```php
Hermesi::fake();

$this->post('/login/otp')->assertOk();

Hermesi::assertMessageSent('sms', fn (SimulatedCall $c) => $c->body['template'] === 'otp-code' && $c->body['recipient'] === '8821');
Hermesi::assertMessageNotSent('email');
Hermesi::assertNoMessageSent();
Hermesi::assertSubscriberWritten('8821', fn (SimulatedCall $c) => $c->body['locale'] === 'fr');
```

A read from the fake (`Hermesi::subscribers()->get()`, `Hermesi::events()->get()`...) throws `SimulationException`: there is nothing to read, and an
invented answer would make a test pass for the wrong reason. Arrange what your code reads by binding your own object in its place.

`fake()` replaces the client everywhere, including where you type-hinted it, and sends nothing. A payload that could not be sent fails in
your test exactly as it would in production. An event published with `Hermesi::dispatch()` is recorded when its job runs: with the `sync`
queue driver that is at once, and with `Queue::fake()` assert on the job instead:

```php
Queue::fake();
Hermesi::dispatch('order.shipped', 'user_1');
Queue::assertPushed(\Hermesi\Laravel\Jobs\TriggerHermesiEvent::class);
```

## The key never shows

The key is read from `.env` by the config and held by the SDK, which never prints it (`var_dump`, `print_r`, `var_export`, `dd()` and
`json_encode` show nothing) and refuses to be serialised, so it cannot end up in a cache or a session. Do not log `config('hermesi')`: that array
holds it.

## Not included

No Laravel notification channel (`$user->notify(...)`) yet. If your application is built around notifications and you want one, say so.

## License

MIT
