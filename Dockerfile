FROM docker.darvagcloud.com/dunglas/frankenphp:alpine AS vendor

RUN ALPINE_VERSION=$(cat /etc/alpine-release | cut -d. -f1,2) && \
    echo "https://mirror.darvagcloud.com/alpine/v$ALPINE_VERSION/main" > /etc/apk/repositories && \
    echo "https://mirror.darvagcloud.com/alpine/v$ALPINE_VERSION/community" >> /etc/apk/repositories

WORKDIR /app

RUN apk add --no-cache git && \
    git clone \
        --depth 1 \
        --branch 6.3.0 \
        --recurse-submodules \
        --shallow-submodules \
        https://github.com/phpredis/phpredis.git \
        /tmp/phpredis && \
    install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        pcntl \
        pdo_mysql \
        /tmp/phpredis \
        zip && \
    rm -rf /tmp/phpredis && \
    apk del git

COPY --from=docker.darvagcloud.com/library/composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_POLICY_ADVISORIES_BLOCK=false

COPY composer.* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts \
    --ignore-platform-req=php


FROM docker.darvagcloud.com/dunglas/frankenphp:alpine

RUN ALPINE_VERSION=$(cat /etc/alpine-release | cut -d. -f1,2) && \
    echo "https://mirror.darvagcloud.com/alpine/v$ALPINE_VERSION/main" > /etc/apk/repositories && \
    echo "https://mirror.darvagcloud.com/alpine/v$ALPINE_VERSION/community" >> /etc/apk/repositories

WORKDIR /app

RUN apk add --no-cache git && \
    git clone \
        --depth 1 \
        --branch 6.3.0 \
        --recurse-submodules \
        --shallow-submodules \
        https://github.com/phpredis/phpredis.git \
        /tmp/phpredis && \
    install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        pcntl \
        pdo_mysql \
        /tmp/phpredis \
        zip && \
    rm -rf /tmp/phpredis && \
    apk del git

# پاسخ‌های جریانی (SSE از gateway مدل‌ها) نباید بافر شوند؛ یک درخواست gateway می‌تواند دقیقه‌ها طول بکشد.
RUN { \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_reporting = E_ALL'; \
        echo 'output_buffering = Off'; \
        echo 'max_execution_time = 0'; \
        echo 'upload_max_filesize = 20M'; \
        echo 'post_max_size = 25M'; \
    } > /usr/local/etc/php/conf.d/99-overrides.ini

COPY --from=docker.darvagcloud.com/library/composer:2 /usr/bin/composer /usr/bin/composer

COPY --from=vendor /app/vendor ./vendor

COPY . .

# پوشه‌های storage باید قبل از package:discover (post-autoload-dump) وجود داشته باشند.
# برنامه Vite ندارد؛ دارایی‌های Filament با filament:assets در تصویر منتشر می‌شوند.
RUN mkdir -p storage/app/private storage/app/public \
        storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && \
    APP_ENV=build composer dump-autoload --optimize && \
    APP_ENV=build php artisan filament:assets && \
    chown -R www-data:www-data storage bootstrap/cache /config /data

USER www-data

EXPOSE 8080

ENV SERVER_NAME=:8080

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
