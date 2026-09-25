# Development image: PHP CLI + Composer. The host needs no PHP install.
# Official php images link ext-curl against Debian's libcurl, which is built with nghttp2 (HTTP/2).
ARG PHP_VERSION=8.2
# bookworm ships libcurl 7.88.1 (no HTTP/2 connection reuse); trixie ships 8.14 (reuse works)
ARG DEBIAN=bookworm
FROM php:${PHP_VERSION}-cli-${DEBIAN}

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

WORKDIR /app
