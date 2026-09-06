# syntax=docker/dockerfile:1.7
#
# Smart Access Control — application image.
#
# One image serves every role in the deployment (web, queue worker, scheduler,
# Reverb websocket server); the role is selected at runtime with the
# CONTAINER_ROLE environment variable. See docker/entrypoint.sh.
#
# Targets:
#   dev   — dev dependencies, source bind-mounted, no baked-in build
#   prod  — no dev dependencies, compiled front-end and cached config baked in
#

# ---------------------------------------------------------------------------
# Stage 1 — front-end build (React + TypeScript + Vite)
# ---------------------------------------------------------------------------
FROM node:22-alpine AS frontend

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.ts tsconfig.json tsconfig.node.json ./
COPY resources ./resources
RUN npm run build


# ---------------------------------------------------------------------------
# Stage 2 — PHP base (extensions, nginx, supervisor). Shared by dev and prod.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

ARG UID=1000
ARG GID=1000

# `install-php-extensions` handles the build-deps dance for us and cleans up
# after itself, which keeps the image small without a hand-written apk cycle.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        pdo_mysql \
        redis \
        bcmath \
        intl \
        zip \
        opcache \
        pcntl \
        sockets \
    && apk add --no-cache \
        nginx \
        supervisor \
        bash \
        curl \
        tzdata \
        mariadb-client \
    && rm -rf /var/cache/apk/*

# Run the application as a non-root user whose ids match the host developer, so
# bind-mounted files written inside the container stay editable outside it.
RUN set -eux; \
    if [ "$(id -u www-data)" != "${UID}" ]; then \
        deluser www-data; \
        addgroup -g "${GID}" -S www-data 2>/dev/null || true; \
        adduser -u "${UID}" -S -D -G www-data -H -h /var/www www-data; \
    fi

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/app.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p /var/log/supervisor /run/nginx /var/lib/nginx/tmp /var/lib/nginx/logs \
    && chown -R www-data:www-data /var/lib/nginx /run/nginx

WORKDIR /var/www/html

ENV CONTAINER_ROLE=app \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

EXPOSE 80 8080

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord"]


# ---------------------------------------------------------------------------
# Stage 3a — development image
# ---------------------------------------------------------------------------
FROM base AS dev

ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1 \
    APP_ENV=local

RUN install-php-extensions xdebug \
    && printf '%s\n' \
        'xdebug.mode=off' \
        'xdebug.client_host=host.docker.internal' \
        'xdebug.start_with_request=trigger' \
        > /usr/local/etc/php/conf.d/zz-xdebug.ini

# Source is bind-mounted in development; vendor/ is installed by the entrypoint
# on first boot so a fresh clone works without a host PHP toolchain.
USER www-data


# ---------------------------------------------------------------------------
# Stage 3b — production image
# ---------------------------------------------------------------------------
FROM base AS vendor

WORKDIR /build
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_HOME=/tmp/composer-cache \
    composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction


FROM base AS prod

ENV APP_ENV=production

COPY --chown=www-data:www-data . /var/www/html
COPY --from=vendor --chown=www-data:www-data /build/vendor /var/www/html/vendor
COPY --from=frontend --chown=www-data:www-data /build/public/build /var/www/html/public/build

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && rm -rf /var/www/html/node_modules /var/www/html/tests \
    && mkdir -p storage/framework/{cache/data,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

USER www-data
