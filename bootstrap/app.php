<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameConfiguration;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameTransition;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (InvalidGameTransition $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'invalid_game_transition',
                ], 422);
            }
        });

        $exceptions->render(function (InvalidGameConfiguration $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'invalid_game_configuration',
                ], 422);
            }
        });
    })->create();
