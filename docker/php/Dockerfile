FROM php:8.4-fpm-bookworm AS development

RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get -o Acquire::Retries=3 update \
    && apt-get install -y --no-install-recommends \
        libfcgi-bin libicu-dev libpq-dev librabbitmq-dev libssl-dev unzip \
    && docker-php-ext-install -j"$(nproc)" intl opcache pcntl pdo_pgsql sockets \
    && pecl install redis amqp \
    && docker-php-ext-enable redis amqp \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/backend

COPY backend/composer.json backend/composer.lock backend/symfony.lock ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY backend ./
RUN composer run-script post-install-cmd \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

CMD ["php-fpm"]
