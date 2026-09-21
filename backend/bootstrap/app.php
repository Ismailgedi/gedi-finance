<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Render terminates TLS at its edge and forwards plain HTTP with
        // X-Forwarded-* headers. Without trusting that proxy, Laravel can't
        // tell the request was HTTPS, which breaks secure-cookie detection
        // and https:// URL generation in production.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'password.changed' => \App\Http\Middleware\EnsurePasswordHasBeenChanged::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request): ?string {
            if ($request->is('api/*')) {
                return null;
            }

            return '/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
