#!/bin/sh
set -e

: "${PORT:=10000}"

# Render assigns a dynamic port via $PORT; point Apache at it.
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan config:cache
php artisan route:cache

exec "$@"
