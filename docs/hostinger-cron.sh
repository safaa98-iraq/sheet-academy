#!/usr/bin/env bash
# Configure hPanel custom cron every minute: /bin/bash /home/USER/domains/DOMAIN/sheet-academy/hostinger-cron.sh
set -euo pipefail
cd "$(dirname "$0")"
PHP_BIN="${PHP_BIN:-php}"
command -v flock >/dev/null || { echo 'flock is required to prevent overlapping queue workers.' >&2; exit 1; }
exec 9>storage/framework/hostinger-cron.lock
flock -n 9 || exit 0
"$PHP_BIN" artisan schedule:run --no-interaction >>storage/logs/hostinger-cron.log 2>&1
"$PHP_BIN" artisan queue:work database --stop-when-empty --max-time=45 --timeout=40 --tries=3 --no-interaction >>storage/logs/hostinger-cron.log 2>&1
