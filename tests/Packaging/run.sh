#!/bin/sh
# What `composer require hermesihq/laravel` serves, used from a real Laravel application rather than through Testbench.
#
# Testbench boots a stand-in for an application and loads the package into it by hand. This builds the archive the way Packagist does,
# creates a fresh application from the `laravel/laravel` skeleton, installs the archive into it, lets Laravel's own package discovery
# find the service provider, and uses the facade, the queued job (sync driver) and the fake against a real HTTP server.
#
# HERMESI_CORE_PATH: a checkout of hermesihq/hermesi, used until that package is on Packagist (harmless after).
set -eu

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
WORK=$(mktemp -d)
trap 'kill $SERVER_PID 2>/dev/null || true; rm -rf "$WORK"' EXIT
SERVER_PID=""
fail() { echo "FAIL  $1"; exit 1; }
ok() { echo "  ok    $1"; }

cd "$ROOT"
composer archive --format=zip --dir="$WORK" --file=pkg --quiet
mkdir "$WORK/pkg" && (cd "$WORK/pkg" && unzip -q ../pkg.zip)
for leaked in tests .github phpunit.xml.dist phpstan.neon.dist; do
  [ ! -e "$WORK/pkg/$leaked" ] || fail "the archive contains $leaked"
done
for needed in src/HermesiServiceProvider.php src/Facades/Hermesi.php src/Jobs/TriggerHermesiEvent.php config/hermesi.php README.md LICENSE CHANGELOG.md; do
  [ -e "$WORK/pkg/$needed" ] || fail "the archive lacks $needed"
done
ok "the archive carries the source, config, README, LICENSE and CHANGELOG, and no tests or tooling"

# A scripted HTTP server, the same one the tests use, started by hand.
STATE="$WORK/server"; mkdir "$STATE"
echo '[]' > "$STATE/queue.json"; : > "$STATE/requests.jsonl"
echo '{"status":202,"body":{"event_id":"evt_pkg_1","status":"accepted","notifications":[],"warnings":[]}}' > "$STATE/default.json"
php "$ROOT/tests/Support/server.php" "$STATE" $$ &
SERVER_PID=$!
for i in 1 2 3 4 5 6 7 8 9 10; do [ -f "$STATE/port" ] && break; sleep 0.5; done
PORT=$(cat "$STATE/port")

# A fresh application.
cd "$WORK"
composer create-project laravel/laravel app --no-interaction --quiet --prefer-dist
cd app
composer config repositories.hermesi-laravel '{"type":"path","url":"'"$WORK"'/pkg","options":{"symlink":false,"versions":{"hermesihq/laravel":"0.1.0"}}}'
if [ -n "${HERMESI_CORE_PATH:-}" ]; then
  composer config repositories.hermesi-core '{"type":"path","url":"'"$HERMESI_CORE_PATH"'","options":{"symlink":false,"versions":{"hermesihq/hermesi":"0.1.0"}}}'
fi
composer config --no-plugins allow-plugins.php-http/discovery true
composer require --no-interaction --quiet hermesihq/laravel:0.1.0
ok "installs into a fresh Laravel application"

php artisan package:discover 2>&1 | grep -q "hermesihq/laravel" || fail "Laravel's package discovery did not find the package"
ok "Laravel's own package discovery finds the service provider"

php artisan vendor:publish --tag=hermesi-config --no-interaction >/dev/null
[ -f config/hermesi.php ] || fail "the config was not published"
ok "the config publishes"

cat >> .env <<EOF
HERMESI_SECRET_KEY=hm_sk_pkg_test_0123456789
HERMESI_BASE_URL=http://127.0.0.1:$PORT
HERMESI_ENVIRONMENT_ID=env_pkg
QUEUE_CONNECTION=sync
HERMESI_MAX_RETRIES=0
EOF

cat > use.php <<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Hermesi\Laravel\Facades\Hermesi;

$direct = Hermesi::trigger('order.shipped', 'user_1', ['order_id' => '1'], idempotencyKey: 'direct-1');
Hermesi::dispatch('order.shipped', 'user_1', ['order_id' => '2'], idempotencyKey: 'queued-2');
$token = Hermesi::token('user_1');
[$payload] = explode('.', $token);
$claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
if ('env_pkg' !== ($claims['env'] ?? null)) { fwrite(STDERR, "token env wrong\n"); exit(1); }
echo "direct={$direct->eventId}\n";

Hermesi::fake();
Hermesi::trigger('order.shipped', 'user_9', ['order_id' => '9']);
Hermesi::assertTriggered('order.shipped');
echo "fake ok\n";
PHP
OUT=$(php use.php) || fail "using the package from the application"
echo "$OUT" | grep -q "direct=evt_pkg_1" || fail "the direct call did not reach the server: $OUT"
echo "$OUT" | grep -q "fake ok" || fail "the fake did not work"

KEYS=$(php -r '
$keys = []; foreach (file($argv[1]) as $line) { $r = json_decode($line, true); if ($r) { $keys[] = $r["headers"]["idempotency-key"] . "@" . $r["headers"]["authorization"]; } }
echo implode(",", $keys);
' "$STATE/requests.jsonl")
[ "$KEYS" = "direct-1@Bearer hm_sk_pkg_test_0123456789,queued-2@Bearer hm_sk_pkg_test_0123456789" ] || fail "the server saw: $KEYS"
ok "the facade, the queued job and the fake work in a real application, and the server received exactly the two events with the configured key"

echo
echo "hermesihq/laravel ok: what Composer would serve installs and works in a real Laravel application."
