# syntax=docker/dockerfile:1
# SMAGA Talent Hub — PHP runtime (dev target). Production stages live in docker/Dockerfile.prod.
FROM php:8.4-fpm-bookworm AS base

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      git unzip libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip gd bcmath exif opcache pcntl \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-sth.ini

WORKDIR /var/www/html

FROM base AS dev
# DEV ONLY: Docker Desktop on Windows exposes the bind-mounted repo as root-owned, read-only for
# other users, so the dev container (php-fpm workers, composer, artisan) runs as root.
# The production image (docker/Dockerfile.prod) runs as a non-root user with files baked into the image.
RUN printf '[www]\nuser = root\ngroup = root\n' > /usr/local/etc/php-fpm.d/zz-dev-user.conf
CMD ["php-fpm", "-R"]
