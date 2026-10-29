FROM composer:2.10.3 AS composer-tool
RUN composer self-update 2.11.0

FROM php:8.4.27-fpm-bookworm AS runtime

RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get -o Acquire::Retries=3 update \
    && apt-get install -y --no-install-recommends \
        libfcgi-bin libicu-dev libpq-dev librabbitmq-dev libssl-dev unzip \
    && docker-php-ext-install -j"$(nproc)" intl opcache pcntl pdo_pgsql sockets \
    && pecl install redis amqp \
    && docker-php-ext-enable redis amqp \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY docker/php/zz-equo-access.conf /usr/local/etc/php-fpm.d/zz-equo-access.conf
COPY docker/php/worker-entrypoint.sh /usr/local/bin/equo-worker-entrypoint
RUN chmod 0755 /usr/local/bin/equo-worker-entrypoint

COPY --from=composer-tool /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/backend

COPY backend/composer.json backend/composer.lock backend/symfony.lock ./

FROM runtime AS production
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
COPY backend ./
RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var
ENV APP_ENV=prod APP_DEBUG=0
CMD ["php-fpm"]

FROM runtime AS development
RUN pecl install xdebug-3.5.3 \
    && docker-php-ext-enable xdebug \
    && rm -rf /tmp/pear
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini

RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY backend ./
RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

CMD ["php-fpm"]
