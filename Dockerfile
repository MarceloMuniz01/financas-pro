# syntax=docker/dockerfile:1

# =========================================================
# PHP base
# =========================================================
FROM php:8.4-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        pcntl \
        pdo_pgsql \
        zip \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html


# =========================================================
# Builder
# Composer + Node + Vite
# =========================================================
FROM php-base AS builder

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node 22 vindo da imagem oficial
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# Primeiro os manifests, aproveitando cache do Docker
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts

COPY package.json package-lock.json ./
RUN npm ci

# Agora o código da aplicação
COPY . .

# Laravel precisa estar completo antes do package discovery.
RUN composer dump-autoload \
        --optimize \
        --no-dev \
    && npm run build \
    && rm -rf node_modules


# =========================================================
# Aplicação PHP-FPM
# =========================================================
FROM php-base AS app

ENV APP_ENV=production
ENV LOG_CHANNEL=stderr

COPY --from=builder --chown=www-data:www-data \
    /var/www/html /var/www/html

RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data \
        storage \
        bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]


# =========================================================
# Nginx
# =========================================================
FROM nginx:1.29-alpine AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

COPY --from=app \
    /var/www/html/public \
    /var/www/html/public

EXPOSE 80