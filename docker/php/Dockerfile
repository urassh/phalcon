FROM php:8.3-cli

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip libffi-dev; \
    docker-php-ext-configure ffi --with-ffi; \
    docker-php-ext-install ffi; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
