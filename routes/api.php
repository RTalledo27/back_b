<?php

declare(strict_types=1);

use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\CancelGameController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\CloseGameSalesController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\CreateGameController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\OpenGameSalesController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\PublishGameController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\ScheduleGameController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Public\ListPublicGamesController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Public\ShowPublicGameController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::model('game', Game::class);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('public')->group(function (): void {
    Route::get('/games', ListPublicGamesController::class);
    Route::get('/games/{slug}', ShowPublicGameController::class)
        ->where('slug', '[a-z0-9-]+');
});

Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->group(function (): void {
        Route::post('/games', CreateGameController::class);
        Route::post('/games/{game}/publish', PublishGameController::class);
        Route::post('/games/{game}/open-sales', OpenGameSalesController::class);
        Route::post('/games/{game}/close-sales', CloseGameSalesController::class);
        Route::post('/games/{game}/schedule', ScheduleGameController::class);
        Route::post('/games/{game}/cancel', CancelGameController::class);
    });
