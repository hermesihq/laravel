# Changelog

All notable changes to `hermesihq/laravel`. This file describes what a consumer gets.

**`0.x` means the public API can still change.** A minor bump may contain a breaking change; a patch bump will not. Each release
lists breaking changes first.

## 0.2.0 (2026-10-07)

### Added

- `Hermesi::messages()`, for the SDK's new direct send (`send`, `get`). The SDK's other new calls (`events()->get()`, and
  `subscribers()->put`, `patch`, `get`, `delete`, `registerChannel`, `removeChannel`, `preferences`, `updatePreferences`) are
  reachable through the existing `events()` and `subscribers()` accessors.
- `Hermesi::fake()` records those writes too: `assertMessageSent`, `assertMessageNotSent`, `assertNoMessageSent` and
  `assertSubscriberWritten`, each with a callback that receives the `SimulatedCall`. `simulatedCalls()` is proxied. A read from the
  fake throws `SimulationException`.

### Changed

- Requires `hermesihq/hermesi` `^0.2` (was `^0.1`), the SDK release that has these calls.

## 0.1.0 (2026-10-05)

First release.

### Added

- `Hermesi` facade and container binding of `Hermesi\Hermesi`, configured from `config/hermesi.php` and `.env`.
- `Hermesi::trigger()`, `Hermesi::token()`, and `Hermesi::events()`, `subscribers()`, `tokens()` for the SDK's resources.
- `Hermesi::dispatch()`: publish an event from a queue worker. Validated at dispatch, with the idempotency key fixed there so that
  a retried or redelivered job cannot send twice. Errors that waiting cannot fix fail the job at once; a `429` with a long `Retry-After`
  releases it for that long.
- `Hermesi::fake()` with `assertTriggered`, `assertNotTriggered`, `assertTriggeredTimes` and `assertNothingTriggered`.
- Laravel 10, 11, 12 and 13. Laravel 10 and 11 are past their security support and every release of them is blocked by Composer's
  security advisory check unless you disable it; the package is tested on them with that check off.
