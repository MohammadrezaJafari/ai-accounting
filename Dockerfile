FROM dunglas/frankenphp:alpine AS vendor

WORKDIR /app

RUN install-php-extensions bcmath exif gd intl mbstring pcntl pdo_mysql redis zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts


FROM dunglas/frankenphp:alpine

WORKDIR /app

RUN install-php-extensions bcmath exif gd intl mbstring pcntl pdo_mysql redis zip

# Streaming responses (SSE from the AI gateway) must not be buffered.
RUN { \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_reporting = E_ALL'; \
        echo 'output_buffering = Off'; \
        echo 'max_execution_time = 0'; \
    } > /usr/local/etc/php/conf.d/99-overrides.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN mkdir -p storage/app/private storage/app/public \
        storage/framework/cache storage/framework/sessions storage/framework/views storage/logs && \
    composer dump-autoload --optimize && \
    php artisan filament:assets && \
    chown -R www-data:www-data storage bootstrap/cache /config /data

USER www-data

EXPOSE 8080

ENV SERVER_NAME=:8080

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
