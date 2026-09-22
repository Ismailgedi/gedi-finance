# Deploying Gedi Finance to Render

This repo ships a `render.yaml` Blueprint for a real-user test deployment:

- `gedi-finance` - one Docker web service running Nginx + PHP-FPM, serving
  both the Laravel API and the built React/Vite SPA from the same origin
  (see `/Dockerfile`)
- `gedi-finance-db` - Render PostgreSQL

Everything is one public service on purpose: the app's login relies on
Sanctum's cookie/session auth (`Auth::guard('web')->login()` in
`AuthController`, not bearer tokens), which needs the browser to see the
API as same-origin. A single service makes that true by construction -
no cross-site-cookie edge cases, no CORS rewrites between two services.

Render Blueprints only work against a connected Git provider (GitHub,
GitLab, or Bitbucket) - they can't be created from a local folder. The
steps below are the parts that need your Render/Git accounts and can't be
scripted.

## 0. Source-of-truth check (do this first)

The canonical repo is `https://gitlab.com/gedi-group/gedi-finance.git`.
Confirm Render is pointed at a source that actually contains the code you
want deployed before doing anything else - do not let Render silently
build from a stale or disconnected mirror. See the note at the bottom of
this file if you're not sure your GitHub/GitLab history matches.

## 1. Push this repo to GitLab (or wherever Render will read from)

```bash
git add .
git commit -m "Prepare Gedi Finance for Render deployment"
git push origin main
```

## 2. Create the Blueprint in Render

1. In the Render dashboard: **New > Blueprint**.
2. Select this repository and the `main` branch.
3. Render reads `render.yaml` at the repo root and proposes: `gedi-finance`
   (Docker web service) and `gedi-finance-db` (Postgres).
4. Apply the blueprint. The first deploy will fail health checks until
   step 3 below is done (`APP_KEY` is required to boot).

## 3. Set the secrets `render.yaml` deliberately leaves blank

`render.yaml` never contains real secrets (`sync: false` fields must be
filled in via the Render dashboard, per Render's own guidance). On
`gedi-finance > Environment`, set:

| Key | How to get it |
|---|---|
| `APP_KEY` | Run `php artisan key:generate --show` locally (or in the Render shell) and paste the `base64:...` output. |
| `ADMIN_EMAIL` | The real login email for the seeded Super Admin. |
| `ADMIN_PASSWORD` | A strong password. **The seeder refuses to run at all if this is missing or blank** (see `backend/database/seeders/DatabaseSeeder.php`) - there is no fallback password. |

Optional, only if you want real emails instead of logged-only password
resets: `RESEND_API_KEY` and set `MAIL_MAILER=resend` (the `resend/resend-php`
SDK is already installed and configured in `backend/config/mail.php`).

## 4. Confirm the service URL matches `render.yaml`

`render.yaml` assumes the service name `gedi-finance` is available, which
gives the predictable URL `https://gedi-finance.onrender.com`. If Render
assigns a different URL (name already taken), update `APP_URL`,
`FRONTEND_URL`, and `SANCTUM_STATEFUL_DOMAINS` to match, then redeploy.
If you later put the service behind a custom domain, update the same
three to that hostname.

## 5. First deploy checklist

- `preDeployCommand: php artisan migrate --force` runs the existing
  Laravel migrations against `gedi-finance-db` before each deploy goes
  live - no destructive commands are run, and nothing is seeded
  automatically.
- To seed the clean baseline (Super Admin user, the five accounts -
  Cash/Bank/EVC/eDahab/JEEB -, categories, units - see
  `backend/database/seeders/DatabaseSeeder.php`, no fake transactions,
  sales, purchases, customers, suppliers, or loans), run once from the
  Render shell on `gedi-finance`:
  ```bash
  php artisan db:seed --force
  ```
- Watch the deploy log, then open the service URL and log in with the
  `ADMIN_EMAIL` / `ADMIN_PASSWORD` you set in step 3.

## Why there's no `VITE_API_URL`

The frontend calls the API with relative paths
(`fetch('/api/...', { credentials: 'include' })`) and relies on
Sanctum's cookie-session auth, which needs the browser to see the API as
same-origin. A separate `VITE_API_URL` pointing at a different host would
make every request cross-site, and browsers increasingly block cookies in
that setup - it would break login for real users, not just be a CORS
annoyance. Instead, the frontend build is copied into Laravel's `public/`
directory at image build time, and Nginx (see `docker/nginx.conf.template`)
routes `/api`, `/sanctum` and `/up` to PHP-FPM and everything else to the
static SPA files, falling back to `index.html` for client-side routes.

## Note on source control (2026-09-22)

This local working copy's git history was created by `git init` in an
earlier session, on a folder that had no `.git` at all - it does **not**
share any commit ancestry with the real GitLab repo
(`gitlab.com/gedi-group/gedi-finance`, currently at `a41828e`). A diff
against GitLab's `a41828e` shows the application code (sales, purchases,
inventory, COGS, receivables, loans, reports, Excel exports, auth) is
identical; the only differences are the Gedi Finance branding (logo,
favicons, colors) and this deployment configuration, neither of which has
been pushed to GitLab yet. Do not treat GitHub (`Ismailgedi/gedi-finance`)
as authoritative - reconcile with the real GitLab history before pushing
anything further.
