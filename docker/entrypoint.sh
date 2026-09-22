#!/bin/sh
set -e

: "${PORT:=10000}"

# Render assigns a dynamic port via $PORT; bake it into the Nginx vhost.
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

php artisan config:clear
php artisan config:cache
php artisan route:cache

# Belt-and-braces database initialization, run on every container start
# rather than relying solely on Render's preDeployCommand - a manually
# configured Render web service (as opposed to one created from this
# repo's render.yaml via Blueprint) does not necessarily run
# preDeployCommand at all, which is how a Render deploy can silently end
# up pointed at a completely uninitialized database. Both commands are
# safe to run on every boot:
#   - migrate --force only applies pending migrations (tracked in the
#     migrations table) - a no-op once the schema is up to date.
#   - db:seed --force (DatabaseSeeder) is fully idempotent - every row it
#     touches (users, roles, accounts, categories, units) is looked up by
#     a stable unique key (email/name) via updateOrCreate/findOrCreate,
#     never inserted unconditionally. It creates zero business records
#     (transactions/sales/purchases/people/suppliers/loans).
# `set -e` above means a genuine failure here (e.g. ADMIN_PASSWORD unset)
# stops the container from starting at all, rather than silently serving
# a broken app - the same "fail loudly" behavior DatabaseSeeder already
# enforces for a missing ADMIN_PASSWORD.
php artisan migrate --force
php artisan db:seed --force

exec "$@"
