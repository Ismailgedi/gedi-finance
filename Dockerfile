# syntax=docker/dockerfile:1
#
# Single production image serving both the Laravel API and the built
# React/Vite SPA from one container - one Render Web Service, same-origin
# by construction, no CORS/cross-site-cookie concerns for the deployed app.
# Nginx serves /api/*, /sanctum/* and /up through PHP-FPM (Laravel) and
# everything else as static files, falling back to the SPA shell for
# client-side (react-router-dom) routes.

# ---- Stage 1: build the frontend (Vite/React) ----
FROM node:22-alpine AS frontend-build
WORKDIR /app/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# ---- Stage 2: install PHP dependencies with Composer ----
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --no-autoloader \
        --ignore-platform-reqs
COPY backend/ .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---- Stage 3: runtime (PHP-FPM 8.4 + Nginx) ----
FROM php:8.4-fpm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        gettext-base \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libonig-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        mbstring \
        bcmath \
        zip \
        gd \
        intl \
        opcache \
    && rm -f /etc/nginx/sites-enabled/default \
    && rm -rf /var/lib/apt/lists/*

# Explicit PHP-FPM worker/error visibility, independent of whatever the
# upstream image happens to default to. A worker's own stdout/stderr (a
# PHP fatal error before Laravel's own exception handler is even
# registered, an uncaught error during bootstrap/autoload, etc.) is
# captured instead of silently discarded, and FPM's own error_log is
# explicit about going to the container's stderr - the same stream
# supervisord already sends nginx's output to, which Render's Logs tab
# reads directly, no shell access required. Appended to the existing
# [www] pool file rather than a second file, since PHP-FPM pool names
# must be unique across included conf files.
RUN { \
        echo ''; \
        echo '; --- explicit worker/error visibility for container logs ---'; \
        echo 'catch_workers_output = yes'; \
        echo 'decorate_workers_output = no'; \
        echo 'php_admin_value[error_log] = /proc/self/fd/2'; \
        echo 'php_admin_flag[log_errors] = on'; \
    } >> /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

COPY --from=vendor /app ./
# The built SPA lands inside Laravel's public/ dir alongside index.php -
# no filename collisions (frontend ships index.html, not index.php).
COPY --from=frontend-build /app/frontend/dist/ ./public/

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Render assigns the real port via $PORT at runtime; 10000 is only the
# documented Render default, used if the platform ever omits the var.
ENV PORT=10000
EXPOSE 10000

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf", "-n"]
