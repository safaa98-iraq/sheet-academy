#!/bin/sh
set -eu
cd /var/www/html
: "${APP_KEY:?APP_KEY is required}"
: "${VIDEO_WORKER_TOKEN:?VIDEO_WORKER_TOKEN is required}"
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
php railway-import.php
unset DEPLOY_DATABASE_DUMP_GZIP_BASE64 DEPLOY_DATABASE_DUMP_SHA256
php artisan config:clear --no-interaction
php artisan migrate --force --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
chown -R www-data:www-data storage bootstrap/cache
chmod 750 storage/app/private
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
