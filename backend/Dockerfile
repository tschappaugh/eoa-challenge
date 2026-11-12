FROM php:8.3-fpm-alpine

RUN docker-php-ext-install pdo_mysql
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist \
    --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize

EXPOSE 9000
