# REFERENCE ONLY - requires the actual Laravel application (composer.lock present).
# Rebuild and resolve image tags to digests before deploying.
# Actual Laravel repository MUST have package.json, package-lock.json, tsconfig & Vite config.
# Each release pins and verifies final image digests and package lockfiles.
FROM node:24-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.4-fpm-alpine AS app
RUN apk add --no-cache icu-libs libpq libzip freetype libjpeg-turbo libpng oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev \
       libzip-dev freetype-dev libjpeg-turbo-dev libpng-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 bcmath exif gd intl mbstring opcache pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    # Photo uploads up to KM_ASSET_MAX_KILOBYTES (10 MB, ADR-022), under Caddy's 25 MB body cap.
    && printf 'upload_max_filesize=12M\npost_max_size=13M\n' > /usr/local/etc/php/conf.d/uploads.ini
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader
COPY . .
COPY --from=frontend /app/public/build ./public/build
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 9000
CMD ["php-fpm", "-F"]

FROM caddy:2.10-alpine AS web
COPY --from=app /var/www/html/public /srv/public
COPY deploy/Caddyfile /etc/caddy/Caddyfile
EXPOSE 80 443
