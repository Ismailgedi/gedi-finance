# Gedi Finance - InfinityFree Upload Steps

Package: `deployment/infinityfree/htdocs/` (~54 MB, of which `vendor/` is
52 MB). **Everything in this package is uploaded flat into your
InfinityFree account's `htdocs/`** - there is no `public/` subfolder.
InfinityFree's free hosting returned its own generic 404 for every
request when the real entry point lived one level down in `public/`
(confirmed against the real server, see the project's deployment
history) - `index.php`, `.htaccess`, and the built React SPA all sit
directly in `htdocs/`, alongside `app/`, `config/`, `vendor/`, etc.

```
Your InfinityFree htdocs/
├── .htaccess
├── index.php
├── index.html
├── assets/
├── brand/
├── icons.svg
├── manifest.webmanifest
├── favicon.ico
├── robots.txt
├── app/
├── artisan
├── bootstrap/
├── composer.json, composer.lock
├── config/
├── database/          <- migrations/seeders/factories only, no .sqlite
├── resources/
├── routes/
├── storage/            <- clean empty skeleton (writable dirs only)
└── vendor/              <- production-only Composer dependencies
```

`vendor/` alone is ~52 MB and tens of thousands of small files - use an
FTP client that supports resuming (FileZilla) rather than the web File
Manager for the bulk upload.

## How to actually get the files onto the server

InfinityFree enforces a hard 10 MB limit per individual uploaded file.
No single file in this package comes anywhere close to that (confirmed:
largest file under 1 MB across all 7,812 files), so this only matters
for whichever single artifact you choose to transfer the package as.

**Primary method - FTP (recommended):** connect FileZilla to
`ftpupload.net` with your InfinityFree FTP credentials and upload the
entire extracted `deployment/infinityfree/htdocs/` folder tree directly,
file-by-file, into the account's `htdocs/` directory. Since every file
is small, the 10 MB cap never applies and nothing needs to be zipped at
all. This is the simplest and most reliable option and needs no
extraction step on the server afterward.

**Fallback method - split ZIP batches (web File Manager):** if FTP
isn't available, `deployment/infinityfree/split-batches/` contains the
same package split into 4 ZIPs, each well under the 10 MB cap, each
rooted so its contents extract directly into `htdocs/` (no extra
wrapper folder, unlike a reference/archival ZIP):

| File | Size | Contents |
|---|---|---|
| `01-app-and-frontend.zip` | ~460 KB | `app/`, `bootstrap/`, `config/`, `database/`, `resources/`, `routes/`, `storage/`, `composer.json/.lock`, `artisan`, `index.php`, `.htaccess`, and the built SPA (`index.html`, `assets/`, `brand/`, etc.) |
| `02-vendor-part1.zip` | ~4.3 MB | `vendor/laravel`, `composer`, `nesbot`, `nikic`, `maatwebsite`, `ramsey`, `nette`, `maennchen`, `psr`, `egulias`, `vlucas`, `nunomaduro`, `dflydev`, `bin` |
| `03-vendor-part2.zip` | ~5.2 MB | `vendor/symfony`, `phpoffice`, `psy`, `league`, `guzzlehttp`, `voku`, `monolog`, `spatie`, `brick`, `resend`, `doctrine`, `markbaker`, `dragonmantank`, `phpoption`, `tijsverkoyen`, `carbonphp` |
| `04-vendor-remainder.zip` | ~10 KB | `vendor/fruitcake`, `graham-campbell`, `autoload.php` |

Upload and extract each one, in any order, directly into `htdocs/` via
the File Manager's "Extract" action - they don't overlap, so there's no
conflict between batches. Verified: the combined contents of all 4 ZIPs
exactly match the full package file-for-file (7,812/7,812 files, zero
missing, zero duplicated).

## `index.php` and the root `.htaccess`

`index.php` loads `__DIR__.'/vendor/autoload.php'` and
`__DIR__.'/bootstrap/app.php'` (no `../`, since there's no `public/`
level to climb out of) and calls `$app->usePublicPath(__DIR__)` so
Laravel's `public_path()` helper resolves to `htdocs/` itself - this
matters for `routes/web.php`'s SPA-fallback route
(`public_path('index.html')`).

`.htaccess` is Laravel's stock front-controller rewrite logic plus two
added rules, both load-bearing (confirmed necessary by local testing,
not just defensive):
- Deny any direct request for a dotfile (`.env`, `.git*`, etc.) outright.
- Deny direct execution of any `.php` file other than `index.php` -
  without this, requesting `/config/database.php` or
  `/app/Models/User.php` directly would execute that file outside
  Laravel's bootstrap and leak a stack trace with the server filesystem
  path.

## The `.env` you create directly on the server

**Never upload a `.env` file** - this package deliberately doesn't
include one (`.gitignore` excludes it, and the packaging process never
copies it in). Create `htdocs/.env` directly via the InfinityFree File
Manager's editor (or FTP) after uploading everything else:

```
APP_NAME="Gedi Finance"
APP_ENV=production
APP_KEY=                      # see "Generating APP_KEY" below - REQUIRED, see note
APP_DEBUG=false
APP_URL=https://gedi-finance.rf.gd
FRONTEND_URL=https://gedi-finance.rf.gd
SANCTUM_STATEFUL_DOMAINS=gedi-finance.rf.gd

DB_CONNECTION=mysql
DB_HOST=sql211.infinityfree.com
DB_PORT=3306
DB_DATABASE=if0_43041931_gedi_finance
DB_USERNAME=if0_43041931
DB_PASSWORD=                  # from the InfinityFree control panel - never committed

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

LOG_LEVEL=error

# Required once, to seed the Super Admin (see "Running migrate + seed"
# below) - DatabaseSeeder refuses to run without this, by design:
ADMIN_NAME="Gedi Finance Admin"
ADMIN_EMAIL=
ADMIN_PASSWORD=

# Optional second Super Admin - leave ISMAIL_ADMIN_PASSWORD unset to
# skip provisioning this account entirely.
ISMAIL_ADMIN_EMAIL=
ISMAIL_ADMIN_PASSWORD=

# Only needed temporarily, to trigger GET /deploy/seed once (see below).
# Make up a long random value yourself - not a real word, not reused
# from anywhere else. Remove this line again once you've used it.
SEED_TOKEN=
```

**NOTE on the exact failure that led to this document being rewritten**:
a previous deployment reached the real application (the document-root
issue was already solved) but every page returned `500 Server Error`.
The log showed `MissingAppKeyException: No application encryption key
has been specified.` - the `.env` on the server was missing `APP_KEY`
entirely. `APP_KEY` is not optional: Laravel's cookie/session encryption
middleware (part of the standard `web` middleware group, which the
login page and every session-backed request goes through) throws this
exception on literally every request until a key is set. Reproduced
locally and confirmed fixed - see "What was verified locally" below.

### Generating `APP_KEY`

Run this on your own machine (never on the server, never via a web
route - this is a local-only Artisan command):

```bash
cd backend
php artisan key:generate --show
```

This prints `base64:...` - a freshly, randomly generated key, unrelated
to Render's key (never reuse Render's `APP_KEY` here, and vice versa -
each deployment target should have its own). Paste the full
`base64:...` value as `APP_KEY` in the server's `.env`. This value is a
secret (see the list below) - it is never committed, never placed in
this repository, and the command above never writes it to a file on its
own (`--show` only prints it to your terminal).

## Running `migrate` + `db:seed` (no SSH/Artisan access)

InfinityFree has no SSH or CLI access, so Artisan commands can't be run
directly on the server. The MySQL schema was already imported separately
(`deployment/gedi_finance_mysql_schema.sql` via phpMyAdmin), so the 42
tables already exist - but `migrate` still needs to run once to make
Laravel's own `migrations` tracking table agree with that state (it's
idempotent - re-running it after the schema already matches is always
safe, confirmed locally), and `db:seed` needs to run to actually create
the Super Admin user, roles, and reference data (accounts, categories,
units) - **this is why `users`/`roles` show 0 rows even though the
schema itself is populated with tables**.

`routes/web.php` has a route built for exactly this: `GET /deploy/seed`.
It is completely inert (404) unless `SEED_TOKEN` is set in `.env` - it
is NOT reachable at all until you deliberately turn it on.

1. Make sure `.env` has `APP_KEY`, the `DB_*` values, `ADMIN_PASSWORD`
   (and optionally `ISMAIL_ADMIN_PASSWORD`), and `SEED_TOKEN` all set.
2. Visit `https://gedi-finance.rf.gd/deploy/seed?token=<your SEED_TOKEN value>`.
3. You should see `Nothing to migrate.` (or a list of migrations if any
   were genuinely pending) followed by `Seeding database.` and `Done.`.
   A wrong/missing token returns `403`/`404` instead - nothing runs.
4. **Remove the `SEED_TOKEN` line from `.env` immediately after** - the
   route becomes unreachable again the moment it's gone, and re-running
   it later (e.g. after a real schema change) just means setting the
   line again temporarily.

`db:seed` is safe to run more than once - confirmed locally: re-running
it a second time left exactly the same 1 user and 2 roles, no
duplicates (`DatabaseSeeder` uses `updateOrCreate`/`findOrCreate`
throughout).

## PHP extensions required

`pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`,
`bcmath`, `fileinfo`, and **`gd`** (required by `phpoffice/phpspreadsheet`,
a dependency of `maatwebsite/excel`, used for the app's Excel report
exports). All standard on InfinityFree's shared PHP; confirm `gd`
specifically in the control panel before relying on Excel exports.

**PHP/Laravel version**: `composer.json` requires PHP `^8.3`. This
deployment already reached a genuine Laravel-level exception
(`MissingAppKeyException`), not a PHP parse/fatal error - if the
account's PHP version were actually incompatible with Laravel 13's PHP
8.3+ syntax, the application would never have booted far enough to
throw a proper Laravel exception at all. This is evidence the PHP
version in use is compatible; no speculative change was made here.

## Permissions required

`storage/` (and its subdirectories) and `bootstrap/cache/` must be
writable by PHP. Default InfinityFree FTP-upload permissions are
normally sufficient; if you see a "could not write" error, set these to
`755` (or `775`) via the File Manager - never `777`.

## How to test after uploading

1. `https://gedi-finance.rf.gd/.env` → `403`/`404`, never the file's
   contents. Check this first, before anything else.
2. `https://gedi-finance.rf.gd/` → the React app (dark login screen),
   not a 500, blank page, or directory listing.
3. Log in with your real `ADMIN_EMAIL`/`ADMIN_PASSWORD`. Then hard-refresh
   an inner page (e.g. `/dashboard`) → still loads the app, not a 404.
4. DevTools → Network tab → `/api/...` requests return JSON.
5. `https://gedi-finance.rf.gd/sanctum/csrf-cookie` → empty `204`.
6. DevTools → Application → Cookies → the session cookie is `Secure`
   and `HttpOnly`.
7. `https://gedi-finance.rf.gd/up` → Laravel's health route, `200`.

## What's excluded from this package (and why)

`.env`, real credentials, `database.sqlite`, SQL dumps containing data,
`node_modules/`, `.git/`, IDE files, `tests/`, dev-only Composer packages
(phpunit, pint, mockery, collision, pail, pao - confirmed absent from
`vendor/`), and log files.

## Which values are secrets (never commit, never put in this repo)

`APP_KEY`, `DB_PASSWORD`, `ADMIN_PASSWORD`, `ISMAIL_ADMIN_PASSWORD`,
`SEED_TOKEN` (while it's set). Everything else in the `.env` block above
(hostnames, database/username, emails, `APP_URL`) is not secret by
itself but still only belongs in the server's own `.env`, never in a
committed file or this documentation with real values filled in.

## What was verified locally (be precise about this)

**Verified locally, live, end-to-end**, using a disposable local Docker
MySQL 8.0 container (destroyed afterward) running this exact packaged
app, in a genuinely production-like configuration
(`APP_ENV=production`, `APP_DEBUG=false`, a real freshly-generated
`APP_KEY`):

- Reproduced the exact reported failure first: with no `APP_KEY` set,
  `GET /` returned `500`, and the log showed the identical
  `MissingAppKeyException` from the bug report.
- Confirmed the fix: with a real `APP_KEY` present, the same request
  returns `200` and the log stays clean.
- `GET /deploy/seed` with no `SEED_TOKEN` configured → `404`. With it
  configured and a wrong token → `403`. With the correct token →
  migrates (no-op, already applied) and seeds successfully.
- Re-ran `/deploy/seed` a second time → identical row counts, confirming
  idempotency.
- A full, real login round-trip against the seeded admin account
  (`/sanctum/csrf-cookie` → `/api/login` with the matching CSRF token) →
  `200`, with the correct user and `Super Admin` role returned.
- `GET /`, `/login`, `/api/products` (`401` JSON, reaches the real API),
  `/sanctum/csrf-cookie` (`204`), `/up` (`200`) - all correct.
- Sensitive paths `/.env`, `/config/database.php`, `/app/Models/User.php`
  → `403` on all three, even with `APP_DEBUG=false`.
- Full backend test suite: 473/473 passed (471 existing + 2 new, covering
  the `/deploy/seed` route's token gating). `npm run build` succeeded.
  `git diff --check` clean.

All test artifacts (the temporary router script used to simulate Apache
rewriting for PHP's built-in dev server, the Docker container, log
entries, regenerated cache files) were removed afterward - the package
at `deployment/infinityfree/htdocs/` is clean.

**Not verified, requires the real InfinityFree server** - genuinely
cannot be tested from this environment: real MySQL connectivity from
that specific host; the `gd` extension's actual availability; real
file-permission behavior; real HTTPS/cookie behavior under the actual
domain; whether the root `.htaccess`'s rewrite rules behave identically
under InfinityFree's actual Apache configuration (already confirmed
reachable and correctly routing in the previous deployment round - this
note only reflects that Apache-specific behavior can't be fully
simulated by PHP's built-in dev server).
