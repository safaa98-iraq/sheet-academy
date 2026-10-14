#!/usr/bin/env bash
# Run from SSH or a one-time hPanel cron: bash /home/USER/domains/DOMAIN/sheet-academy/hostinger-finalize.sh
set -euo pipefail
cd "$(dirname "$0")"
PHP_BIN="${PHP_BIN:-php}"
[[ -f .env ]] || { echo 'Create .env from .env.example and fill the production values first.' >&2; exit 1; }
"$PHP_BIN" -r 'if (PHP_VERSION_ID < 80300) { fwrite(STDERR, "PHP 8.3 or newer is required.\n"); exit(1); }'
"$PHP_BIN" artisan config:clear --no-interaction
"$PHP_BIN" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment("production") || config("app.debug") || ! str_starts_with(config("app.url"), "https://")) { fwrite(STDERR, "Use production, APP_DEBUG=false and an HTTPS APP_URL.\n"); exit(1); }
if (! config("app.key")) { fwrite(STDERR, "Set a new APP_KEY for a fresh site, or preserve the original APP_KEY when migrating existing data.\n"); exit(1); }
Illuminate\Support\Facades\DB::connection()->getPdo();
'
chmod 600 .env
chmod -R u+rwX,go-rwx storage bootstrap/cache
"$PHP_BIN" artisan migrate --force --no-interaction
# Explicit first-install option only: the seeder resets the configured admin password.
if [[ "${SEED_INITIAL_ADMIN:-0}" == 1 ]]; then
    "$PHP_BIN" artisan db:seed --force --no-interaction
fi
if [[ ! -e ../public_html/storage && ! -L ../public_html/storage ]]; then
    ln -s "$(pwd)/storage/app/public" ../public_html/storage
fi
"$PHP_BIN" artisan config:cache --no-interaction
"$PHP_BIN" artisan route:cache --no-interaction
"$PHP_BIN" artisan view:cache --no-interaction
printf '%s\n' 'Laravel setup completed. Configure an every-minute cron to run hostinger-cron.sh.' 'Video processing and PDF rendering require separate capability checks; setup does not verify them.'
