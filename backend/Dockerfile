# syntax=docker/dockerfile:1

# ---- Stage 1: install PHP dependencies with Composer ----
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --no-autoloader \
        --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---- Stage 2: runtime image (Apache + PHP 8.3) ----
FROM php:8.3-apache AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
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
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Laravel serves from public/, not the repo root.
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!/var/www/html/public/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

COPY --from=vendor /app ./

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Render assigns the real port via $PORT at runtime; 10000 is only the
# documented Render default, used if the platform ever omits the var.
ENV PORT=10000
EXPOSE 10000

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
