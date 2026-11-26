<?php

use App\Http\Middleware\EnsurePlatformRole;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            'permission' => RequirePermission::class,
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

        // Refusals look the same everywhere and never echo back the protected record.
        $exceptions->render(fn (AccessDeniedHttpException $exception, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'You do not have permission to do this. Ask your company administrator if you need access.', 'code' => 'PERMISSION_DENIED'], 403)
            : null);

        $exceptions->render(fn (NotFoundHttpException $exception, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'We could not find that. It may have been removed, or you may not have access to it.', 'code' => 'NOT_FOUND'], 404)
            : null);
    })->create();
