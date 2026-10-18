<?php

use Fruitcake\Cors\CorsService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.auth' => \App\Http\Middleware\EnsureApiAuthenticated::class,
            'security' => \App\Http\Middleware\EnforceSecurityPolicy::class,
            'rbac' => \App\Http\Middleware\EnforceRbac::class,
            'audit.api' => \App\Http\Middleware\AuditApiActions::class,
            'verified.api' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);
        $middleware->trustProxies(at: '*');
        $middleware->use([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        $middleware->append(\App\Http\Middleware\AddSecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // Thrown responses (403, 429, 500) leave the CORS / security middleware
        // before headers are attached. Re-apply them so browsers and scanners
        // still see a hardened error response.
        $exceptions->respond(function (Response $response, \Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->is('sanctum/csrf-cookie')) {
                return app(\App\Http\Middleware\AddSecurityHeaders::class)->apply($request, $response);
            }

            $cors = app(CorsService::class);
            $cors->setOptions((array) config('cors', []));
            $response = $cors->addActualRequestHeaders($response, $request);

            return app(\App\Http\Middleware\AddSecurityHeaders::class)->apply($request, $response);
        });
    })->create();
