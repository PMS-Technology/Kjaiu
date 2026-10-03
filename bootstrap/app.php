<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // The public API is mounted at the domain root (the original platform
        // serves it from /v1/*, not /api/v1/*), so the default "api" prefix is
        // cleared and the version segment is declared in the route file.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        // The administrator API is registered as a web route file so it keeps
        // session handling; its prefix comes from config('kjaiu.admin_path').
        then: function () {
            Route::middleware('web')
                ->group(base_path('routes/admin.php'));

            // The administrator SPA shell is registered last so it never
            // shadows the JSON API that shares its prefix.
            Route::middleware('web')
                ->group(base_path('routes/admin_spa.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.auth' => App\Http\Middleware\AuthenticateApi::class,
            'admin.auth' => App\Http\Middleware\AuthenticateAdmin::class,
            'client.auth' => App\Http\Middleware\AuthenticateClient::class,
        ]);

        // The public API authenticates with a JWT header and is stateless, so
        // it intentionally keeps the framework's default api group (no session
        // or CSRF middleware) rather than the Sanctum stateful group.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('v1/*') || $request->expectsJson(),
        );

        // API failures keep the platform envelope so downstream clients can
        // parse them the same way as successful responses.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('v1/*') && ! $request->is('api/*')) {
                return null;
            }

            $status = $e instanceof Illuminate\Validation\ValidationException ? 406
                : ($e instanceof Illuminate\Auth\AuthenticationException ? 401 : 400);

            $message = $e instanceof Illuminate\Validation\ValidationException
                ? (string) collect($e->errors())->flatten()->first()
                : ($e->getMessage() ?: '操作失败');

            return response()->json([
                'status' => $status,
                'msg' => $message,
                'data' => null,
            ], 200);
        });
    })->create();
