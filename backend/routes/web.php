<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| One-time deployment helper (migrate + seed with no SSH/CLI access)
|--------------------------------------------------------------------------
|
| InfinityFree (and shared hosts like it) have no SSH or Artisan CLI
| access, so `php artisan migrate` / `db:seed` can't be run directly on
| the server. This route lets them run via a single authenticated GET
| request instead - see INFINITYFREE_UPLOAD_STEPS.md for the full
| procedure.
|
| Inert everywhere by default, including on Render: SEED_TOKEN has no
| default and is never set in render.yaml, so `filled($expectedToken)`
| is false there and this always 404s - leaving this route in the shared
| codebase makes no functional difference to the Render deployment. It
| only does anything on a host where SEED_TOKEN has been deliberately
| set in .env, and even then only to someone who knows that exact value
| (hash_equals - no timing side-channel). db:seed is safe to re-run any
| number of times - DatabaseSeeder uses updateOrCreate/findOrCreate
| throughout and never duplicates rows.
*/
Route::get('/deploy/seed', function (\Illuminate\Http\Request $request) {
    $expectedToken = env('SEED_TOKEN');
    abort_unless(filled($expectedToken), 404);
    abort_unless(hash_equals((string) $expectedToken, (string) $request->query('token', '')), 403);

    $exitCode = Artisan::call('migrate', ['--force' => true]);
    $output = Artisan::output();

    if ($exitCode !== 0) {
        return response("migrate failed (exit {$exitCode}):\n\n{$output}", 500)
            ->header('Content-Type', 'text/plain');
    }

    $exitCode = Artisan::call('db:seed', ['--force' => true]);
    $output .= "\n" . Artisan::output();

    return response(
        $output . "\n" . ($exitCode === 0 ? 'Done. Remove SEED_TOKEN from .env now.' : "db:seed failed (exit {$exitCode}).")
    )->header('Content-Type', 'text/plain');
});

/*
|--------------------------------------------------------------------------
| SPA fallback
|--------------------------------------------------------------------------
|
| On Render, Nginx (docker/nginx.conf.template) serves the built React SPA
| and its static assets directly and routes /api, /sanctum and /up to
| PHP-FPM - this Laravel route is never actually reached there, since
| Nginx answers "/" and every other unmatched path with the SPA's
| index.html (or a real static file) before PHP ever sees the request.
|
| On a plain Apache host with no Nginx in front of it (e.g. an
| InfinityFree-style shared-hosting UAT deployment, see
| INFINITYFREE_DEPLOYMENT.md), the standard Laravel public/.htaccess
| already forwards any request that isn't a real file or directory to
| this front controller - including every react-router-dom client route
| (/dashboard, /products, ...). Without a route to answer those here,
| Apache-fronted deployments would get Laravel's 404 instead of the SPA
| shell on a hard refresh of any non-root page. This route exists for
| that case; it is inert (dead code, never invoked) wherever Nginx (or
| an equivalent static-file-first web server) already handles it first.
|
| The negative lookahead excludes api/*, sanctum/*, up and deploy/* so
| this can never swallow a real Laravel endpoint regardless of route
| registration order - it only ever matches a path that isn't one of
| those AND didn't already match a real file.
*/
Route::get('/{any?}', function () {
    $index = public_path('index.html');

    // No built SPA present (e.g. API-only local dev via `php artisan
    // serve` without ever having run `npm run build`) - a clean 404
    // rather than a confusing blank page or stack trace.
    abort_unless(file_exists($index), 404);

    return response()->file($index);
})->where('any', '^(?!api|sanctum|up|deploy).*$')->name('spa.fallback');
