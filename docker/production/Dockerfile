# syntax=docker/dockerfile:1.7

ARG PHP_IMAGE=php:8.4.23-fpm-alpine3.23
ARG NODE_IMAGE=node:22.23.1-alpine3.24
ARG COMPOSER_IMAGE=composer:2.8.12
ARG NGINX_IMAGE=nginx:1.30.0-alpine3.23

FROM ${PHP_IMAGE} AS php-base

ARG PHP_REDIS_VERSION=6.2.0

RUN apk add --no-cache \
        curl \
        fcgi \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libpq \
        libzip \
        oniguruma \
        tesseract-ocr \
        tesseract-ocr-data-deu \
        tesseract-ocr-data-eng \
        tesseract-ocr-data-fra \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install "redis-${PHP_REDIS_VERSION}" \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini
COPY docker/production/php.ini /usr/local/etc/php/conf.d/20-gaeld-production.ini
COPY docker/production/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-gaeld-production.conf

WORKDIR /var/www/html

ENV APP_ENV=production \
    APP_DEBUG=false \
    COMPOSER_ALLOW_SUPERUSER=1

FROM ${COMPOSER_IMAGE} AS composer-bin

FROM php-base AS vendor

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY . /var/www/html

RUN apk add --no-cache git \
    && composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
    && composer clear-cache

FROM ${NODE_IMAGE} AS assets

WORKDIR /app

RUN corepack enable

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile

COPY public ./public
COPY resources ./resources
COPY vite.config.js ./vite.config.js

RUN pnpm run build \
    && find public/build -type f -name '*.map' -delete

FROM php-base AS app

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build
COPY --chown=www-data:www-data docker/production/entrypoint.sh /usr/local/bin/gaeld-entrypoint

RUN chmod 0755 /usr/local/bin/gaeld-entrypoint \
    && mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && rm -f public/hot public/storage \
    && ln -s ../storage/app/public public/storage \
    && chown -R www-data:www-data bootstrap/cache storage \
    && php-fpm -t

USER www-data

ENTRYPOINT ["/usr/local/bin/gaeld-entrypoint"]
CMD ["php-fpm", "--nodaemonize"]

# Provenance last: these change on every commit, and anything below an ARG is
# rebuilt when it changes. Kept here, the expensive layers above stay cached.
# Without them an image cannot be traced back to the source it was built from —
# answering "what is in this image?" otherwise means hashing its source tree.
ARG GIT_REVISION=unknown
ARG IMAGE_VERSION=unknown
ARG BUILD_DATE=unknown
LABEL org.opencontainers.image.title="Gäld application" \
      org.opencontainers.image.revision="${GIT_REVISION}" \
      org.opencontainers.image.version="${IMAGE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}"

FROM ${NGINX_IMAGE} AS web

COPY docker/production/nginx.conf /etc/nginx/nginx.conf
COPY --from=app /var/www/html/public /var/www/html/public

RUN nginx -t

EXPOSE 8080

ARG GIT_REVISION=unknown
ARG IMAGE_VERSION=unknown
ARG BUILD_DATE=unknown
LABEL org.opencontainers.image.title="Gäld web server" \
      org.opencontainers.image.revision="${GIT_REVISION}" \
      org.opencontainers.image.version="${IMAGE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}"
