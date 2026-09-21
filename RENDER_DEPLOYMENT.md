# Deploying Gedi Finance to Render

This repo ships a `render.yaml` Blueprint for a real-user test deployment:

- `gedi-finance-api` - Laravel 13 backend, Docker web service
- `gedi-finance-web` - Vite/React frontend, static site
- `gedi-finance-db` - Render PostgreSQL

Render Blueprints only work against a connected Git provider (GitHub, GitLab,
or Bitbucket) - they can't be created from a local folder. The steps below
are the parts that need your Render/Git accounts and can't be scripted.

## 1. Push this repo to GitHub or GitLab

```bash
git init                       # this folder isn't a git repo yet
git add .
git commit -m "Prepare Gedi Finance for Render deployment"
git remote add origin <your-repo-url>
git branch -M main
git push -u origin main
```

## 2. Create the Blueprint in Render

1. In the Render dashboard: **New > Blueprint**.
2. Select this repository and the `main` branch.
3. Render reads `render.yaml` at the repo root and proposes:
   `gedi-finance-api` (Docker web service), `gedi-finance-web` (static site),
   `gedi-finance-db` (Postgres).
4. Apply the blueprint. The first deploy of `gedi-finance-api` will fail
   health checks until step 3 is done (APP_KEY is required to boot).

## 3. Set the secrets `render.yaml` deliberately leaves blank

`render.yaml` never contains real secrets (`sync: false` fields must be
filled in via the Render dashboard, per Render's own guidance). On
`gedi-finance-api > Environment`, set:

| Key | How to get it |
|---|---|
| `APP_KEY` | Run `php artisan key:generate --show` locally (or in the Render shell) and paste the `base64:...` output. |
| `ADMIN_EMAIL` | The real login email for the seeded Super Admin. |
| `ADMIN_PASSWORD` | A strong password. **The seeder now refuses to run at all if this is missing or blank** (see `database/seeders/DatabaseSeeder.php`) - there is no fallback password. |

Optional, only if you want real emails instead of logged-only password
resets: `RESEND_API_KEY` and set `MAIL_MAILER=resend` (the `resend/resend-php`
SDK is already installed and configured in `config/mail.php`).

## 4. Confirm the two service URLs match `render.yaml`

`render.yaml` assumes the service names `gedi-finance-api` /
`gedi-finance-web` are available, which gives predictable URLs:
`https://gedi-finance-api.onrender.com` and `https://gedi-finance-web.onrender.com`.
If Render assigns different URLs (name already taken), update:

- `gedi-finance-api`'s `APP_URL` and `FRONTEND_URL` env vars
- `gedi-finance-api`'s `SANCTUM_STATEFUL_DOMAINS` env var
- The two `destination` URLs in `gedi-finance-web`'s rewrite routes in
  `render.yaml` (redeploy the blueprint after editing)

If you later put both services behind a custom domain (e.g.
`app.gedifinance.com` / `api.gedifinance.com`), update the same four
places to the custom hostnames.

## 5. First deploy checklist

- `preDeployCommand: php artisan migrate --force` runs the existing Laravel
  migrations against `gedi-finance-db` before each deploy goes live - no
  destructive commands are run, and nothing is seeded automatically.
- To seed the reference data (Super Admin user, default accounts,
  categories, units - see `database/seeders/DatabaseSeeder.php`, no fake
  transactions), run once from the Render shell on `gedi-finance-api`:
  ```bash
  php artisan db:seed --force
  ```
- Watch the deploy logs for both services, then open the frontend URL and
  log in with the `ADMIN_EMAIL` / `ADMIN_PASSWORD` you set in step 3.

## Why there's no `VITE_API_URL`

The frontend already calls the API with relative paths
(`fetch('/api/...', { credentials: 'include' })`) and relies on
Sanctum's cookie-session auth, which needs the browser to see the API as
same-origin. Introducing a separate `VITE_API_URL` and pointing it at a
different Render subdomain would make every request cross-site, and
browsers increasingly block cookies in that setup - it would break login
for a meaningful share of real users, not just be a CORS annoyance.
Instead, `gedi-finance-web`'s rewrite rules proxy `/api/*` and
`/sanctum/*` to the backend at Render's edge, keeping everything
same-origin exactly like the local Vite dev proxy already does.
