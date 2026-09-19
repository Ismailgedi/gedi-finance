# Apply this Phase 1 package

1. Keep the existing fresh Laravel 13.31 project.
2. Copy the `app`, `database`, `routes`, and `docs` contents from this package into the Laravel project, merging directories.
3. Copy `.env.postgres.example` values into the project's `.env`. Do not commit the real `.env`.
4. Make sure PostgreSQL is running.
5. Run `php artisan migrate:fresh --seed` only on a brand-new local database. This command deletes all existing database data.
6. For an existing database, use `php artisan migrate --seed` instead.
7. Laravel 13 fresh projects may need API routing enabled. If `routes/api.php` is not registered, run `php artisan install:api` and keep the generated API routing setup.
8. Run `php artisan route:list` and verify the Gedi Finance endpoints.
