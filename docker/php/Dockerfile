# PHP 8.3 (our minimum) with two Composer binaries:
# - `composer`: newest Composer 2.x at build time.
# - `composer-2.9`: newest Composer 2.9.x at build time (our minimum).
FROM composer:2.9 AS composer-min
FROM composer:2 AS composer-latest

FROM php:8.3-cli

# git and patch: composer-patches v2 applies patches with either.
# unzip and the zip extension: Composer extracts dist archives.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git patch unzip libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer-latest /usr/bin/composer /usr/local/bin/composer
COPY --from=composer-min /usr/bin/composer /usr/local/bin/composer-2.9

# The owner of the project mount can be a different user than the container user.
RUN git config --system safe.directory '*'

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_CACHE_DIR=/composer-cache

WORKDIR /app
