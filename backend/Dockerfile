FROM php:8.4-fpm-alpine

RUN apk add --no-cache $PHPIZE_DEPS postgresql-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl zip opcache mbstring \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . /var/www

RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm", "-F"]
