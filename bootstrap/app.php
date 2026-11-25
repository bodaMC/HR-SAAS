<?php

use App\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => EnsureTenantContext::class,
        ]);

        $middleware->api(append: [SetApiLocale::class]);

        $middleware->priority([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            EnsureFrontendRequestsAreStateful::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            Authenticate::class,
            EnsureTenantContext::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A national-ID unique violation is an expected, handled outcome. Do not
        // report it: the exception's SQL bindings contain the full ID and must
        // never reach the general log.
        $exceptions->dontReportWhen(
            fn (Throwable $e) => $e instanceof UniqueConstraintViolationException
                && (str_contains($e->getMessage(), 'employees_company_national_id_unique') || str_contains($e->getMessage(), 'employees.national_id'))
        );

        // Concurrent duplicate national ID: the DB unique index won the race.
        // Map it to the same field-level 422 as validation, without leaking SQL.
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if (($request->is('api/*') || $request->expectsJson()) && (str_contains($e->getMessage(), 'employees_company_national_id_unique') || str_contains($e->getMessage(), 'employees.national_id'))) {
                $msg = __('national_id.duplicate');

                return response()->json(['message' => $msg, 'errors' => ['national_id' => [$msg]]], 422);
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
