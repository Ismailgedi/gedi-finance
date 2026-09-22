#!/bin/sh
set -e

: "${PORT:=10000}"

# Render assigns a dynamic port via $PORT; bake it into the Nginx vhost.
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

php artisan config:cache
php artisan route:cache

exec "$@"
