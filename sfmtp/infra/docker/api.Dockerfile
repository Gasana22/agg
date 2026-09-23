# SFMTP API (Laravel 12) on FrankenPHP. The same image runs the API,
# queue worker, scheduler and one-off migrations (see compose.yaml).
# Build context: sfmtp/  →  docker build -f infra/docker/api.Dockerfile .

FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
# Extensions live in the runtime image, not in the composer image.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

FROM dunglas/frankenphp:1-php8.4 AS runtime
RUN install-php-extensions pdo_pgsql pdo_mysql redis intl zip opcache pcntl bcmath
ENV SERVER_NAME=":8000" \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr
WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY backend/ ./
COPY --from=vendor /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && rm /usr/bin/composer \
    && mkdir -p storage/framework/cache storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /config/caddy /data/caddy \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

USER www-data
EXPOSE 8000
HEALTHCHECK --interval=15s --timeout=3s --retries=5 CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8000/up") === false ? 1 : 0);'
# Caches are built at start-up, when the real environment is known.
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && frankenphp php-server --listen :8000 --root public/"]
