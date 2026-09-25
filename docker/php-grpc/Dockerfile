# Dev image with ext-grpc, for ExtGrpcTransport tests and the transport benchmark (ADR 0002 spike).
# Compiling ext-grpc takes several minutes.
ARG PHP_VERSION=8.2
ARG DEBIAN=bookworm
FROM php:${PHP_VERSION}-cli-${DEBIAN}

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip zlib1g-dev \
    && rm -rf /var/lib/apt/lists/* \
    && MAKEFLAGS="-j$(nproc)" pecl install grpc-1.84.0 \
    && docker-php-ext-enable grpc

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

WORKDIR /app
