<?php

use App\Http\Middleware\EnsurePlatformRole;
use App\Http\Middleware\ResolveCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'company' => ResolveCompany::class,
            'platform' => EnsurePlatformRole::class,
        ]);

        // Company-scoped models are resolved by route model binding, so the company must be set first.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveCompany::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
