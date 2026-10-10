FROM node:22-bookworm-slim AS frontend
WORKDIR /build
COPY package*.json ./
RUN npm ci --ignore-scripts
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
RUN npm run build

FROM php:8.4-apache-bookworm AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg poppler-utils supervisor libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
    libfreetype6-dev libonig-dev libxml2-dev libsqlite3-dev ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 pdo_mysql pdo_sqlite intl zip gd bcmath pcntl mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html

FROM php-base AS verified-app
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY . .
COPY --from=frontend /build/public/build ./public/build
RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
    && npm ci --omit=dev --ignore-scripts --prefix video-worker \
    && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan test --compact \
    && node --test tests/Unit/*.test.mjs \
    && vendor/bin/pint --format agent config/trustedproxy.php railway-import.php \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && rm -rf tests .claude .codex .github electron docs

FROM php-base AS production
COPY --from=verified-app /var/www/html /var/www/html
COPY railway-apache.conf /etc/apache2/sites-available/000-default.conf
COPY railway-supervisor.conf /etc/supervisor/conf.d/academy.conf
COPY railway-php.ini /usr/local/etc/php/conf.d/academy.ini
RUN a2dismod mpm_event mpm_worker && a2enmod mpm_prefork \
    && apache2ctl -t \
    && chmod +x railway-start.sh && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 80
ENTRYPOINT ["/var/www/html/railway-start.sh"]
