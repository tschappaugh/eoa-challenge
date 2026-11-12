# Shared runtime: extensions and production php.ini.
FROM php:8.3-fpm-alpine AS base
RUN apk add --no-cache icu-libs \
    && apk add --no-cache --virtual .build-deps icu-dev \
    && docker-php-ext-install intl opcache pdo_mysql \
    && apk del .build-deps \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
WORKDIR /var/www/html
EXPOSE 9000

# Test image: dev dependencies and the test suite (docker-compose "test" profile).
FROM base AS dev
COPY docker/php-dev.ini "$PHP_INI_DIR/conf.d/zz-dev.ini"
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload

# Runtime image: no dev dependencies, no tests, no Composer.
FROM base AS prod
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY src ./src
COPY public ./public
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && rm /usr/local/bin/composer
