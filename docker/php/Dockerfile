# PHP-FPM 8.3 cho backend Laravel (dùng chung cho app, queue worker, scheduler)
FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-libs libzip libpq \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip opcache pcntl bcmath \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Chạy với UID/GID của user trên máy dev để file tạo ra không bị thuộc root
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} app && adduser -D -u ${UID} -G app app
USER app

WORKDIR /var/www/backend
