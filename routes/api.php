<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CreatePlayerController;
use App\Http\Controllers\Auth\ActivateController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SocialAccountsController;
use App\Http\Controllers\Auth\SocialCallbackController;
use App\Http\Controllers\Auth\SocialExchangeController;
use App\Http\Controllers\Auth\SocialLinkCallbackController;
use App\Http\Controllers\Auth\SocialLinkRedirectController;
use App\Http\Controllers\Auth\SocialRedirectController;
use App\Http\Controllers\Auth\UnlinkSocialAccountController;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PaymentDocument;
use App\Modules\Commerce\Presentation\Http\Controllers\Admin\ApprovePaymentController;
use App\Modules\Commerce\Presentation\Http\Controllers\Admin\DownloadPaymentDocumentController;
use App\Modules\Commerce\Presentation\Http\Controllers\Admin\ListAdminOrdersController;
use App\Modules\Commerce\Presentation\Http\Controllers\Admin\ListAdminPaymentsController;
use App\Modules\Commerce\Presentation\Http\Controllers\Admin\ListGameNumbersAdminController;
use App\Modules\Commerce\Presentation\Http\Controllers\Admin\RejectPaymentController;
use App\Modules\Commerce\Presentation\Http\Controllers\Admin\ShowAdminPaymentController;
use App\Modules\Commerce\Presentation\Http\Controllers\Player\CancelOrderController;
use App\Modules\Commerce\Presentation\Http\Controllers\Player\ListMyEntriesController;
use App\Modules\Commerce\Presentation\Http\Controllers\Player\ListMyOrdersController;
use App\Modules\Commerce\Presentation\Http\Controllers\Player\ListMyReservationsController;
use App\Modules\Commerce\Presentation\Http\Controllers\Player\ReserveGameNumbersController;
use App\Modules\Commerce\Presentation\Http\Controllers\Player\ShowMyOrderController;
use App\Modules\Commerce\Presentation\Http\Controllers\Player\SubmitPaymentEvidenceController;
use App\Modules\Commerce\Presentation\Http\Controllers\Public\ListGameNumbersPublicController;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\CancelGameController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\CloseGameSalesController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\CreateGameController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\DrawGameNumberController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\ListAdminGamesController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\ListGameCountersController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\ListGameDrawsController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\OpenGameSalesController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\PauseGameController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\PublishGameController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\RebuildCountersController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\ResumeGameController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\ScheduleGameController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\ShowAdminGameController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\ShowGameWinnerController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin\StartGameController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Public\ListPublicGameDrawsController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Public\ListPublicGameNumberCountersController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Public\ListPublicGamesController;
use App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Public\ShowPublicGameController;
use Illuminate\Support\Facades\Route;

Route::model('game', Game::class);
Route::model('order', Order::class);
Route::model('payment', Payment::class);
Route::model('document', PaymentDocument::class);

Route::prefix('auth')->group(function (): void {
    Route::post('/register', RegisterController::class)
        ->middleware('throttle:auth.register');
    Route::post('/login', LoginController::class)
        ->middleware('throttle:auth.login');
    Route::post('/activate', ActivateController::class)
        ->middleware('throttle:auth.activate');

    Route::prefix('social')->group(function (): void {
        // ── Login flow (public) ───────────────────────────────────────────────
        Route::get('/{provider}/redirect', SocialRedirectController::class)
            ->where('provider', 'google|facebook')
            ->middleware('throttle:auth.social.redirect');

        Route::get('/{provider}/callback', SocialCallbackController::class)
            ->where('provider', 'google|facebook')
            ->middleware('throttle:auth.social.callback');

        Route::post('/exchange', SocialExchangeController::class)
            ->middleware('throttle:auth.social.exchange');

        // ── Link callback (public — OAuth provider cannot send Bearer token) ─
        Route::get('/{provider}/link/callback', SocialLinkCallbackController::class)
            ->where('provider', 'google|facebook')
            ->middleware('throttle:auth.social.link.callback');

        // ── Link & unlink (require authenticated user) ─────────────────────
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/{provider}/link/redirect', SocialLinkRedirectController::class)
                ->where('provider', 'google|facebook')
                ->middleware('throttle:auth.social.link.redirect');

            Route::delete('/{provider}', UnlinkSocialAccountController::class)
                ->where('provider', 'google|facebook')
                ->middleware('throttle:auth.social.unlink');
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', LogoutController::class);
        Route::get('/me', MeController::class);
        Route::get('/social-accounts', SocialAccountsController::class)
            ->name('auth.social-accounts.index');
    });
});

Route::middleware('auth:sanctum')
    ->get('/user', MeController::class)
    ->name('auth.user.legacy');

Route::prefix('public')->group(function (): void {
    Route::get('/games', ListPublicGamesController::class);
    Route::get('/games/{slug}', ShowPublicGameController::class)
        ->where('slug', '[a-z0-9-]+');
    Route::get('/games/{slug}/numbers', ListGameNumbersPublicController::class)
        ->where('slug', '[a-z0-9-]+');
    Route::get('/games/{slug}/draws', ListPublicGameDrawsController::class)
        ->where('slug', '[a-z0-9-]+')
        ->name('public.games.draws.index');
    Route::get('/games/{slug}/number-counters', ListPublicGameNumberCountersController::class)
        ->where('slug', '[a-z0-9-]+')
        ->name('public.games.number-counters.index');
});

Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->group(function (): void {
        Route::post('/players', CreatePlayerController::class)
            ->middleware('throttle:admin.create-player');
        Route::get('/games', ListAdminGamesController::class)
            ->name('admin.games.index');
        Route::get('/games/{game}', ShowAdminGameController::class)
            ->name('admin.games.show');
        Route::post('/games', CreateGameController::class);
        Route::post('/games/{game}/publish', PublishGameController::class);
        Route::post('/games/{game}/open-sales', OpenGameSalesController::class);
        Route::post('/games/{game}/close-sales', CloseGameSalesController::class);
        Route::post('/games/{game}/schedule', ScheduleGameController::class);
        Route::post('/games/{game}/cancel', CancelGameController::class);

        // Phase 3.8 — engine endpoints
        Route::post('/games/{game}/start', StartGameController::class)
            ->name('admin.games.start');
        Route::post('/games/{game}/pause', PauseGameController::class)
            ->name('admin.games.pause');
        Route::post('/games/{game}/resume', ResumeGameController::class)
            ->name('admin.games.resume');
        Route::post('/games/{game}/draws', DrawGameNumberController::class)
            ->name('admin.games.draws.store');
        Route::post('/games/{game}/counters/rebuild', RebuildCountersController::class)
            ->name('admin.games.counters.rebuild');
        Route::get('/games/{game}/draws', ListGameDrawsController::class)
            ->name('admin.games.draws.index');
        Route::get('/games/{game}/counters', ListGameCountersController::class)
            ->name('admin.games.counters.index');
        Route::get('/games/{game}/winner', ShowGameWinnerController::class)
            ->name('admin.games.winner.show');
    });

Route::middleware(['auth:sanctum', 'idempotent'])
    ->post('/games/{game}/reservations', ReserveGameNumbersController::class);

Route::middleware(['auth:sanctum', 'idempotent'])
    ->post('/me/orders/{order}/payment-evidence', SubmitPaymentEvidenceController::class);

Route::middleware(['auth:sanctum', 'admin', 'idempotent'])
    ->prefix('admin')
    ->group(function (): void {
        Route::post('/payments/{payment}/approve', ApprovePaymentController::class);
        Route::post('/payments/{payment}/reject', RejectPaymentController::class);
    });

Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->group(function (): void {
        Route::get('/payments', ListAdminPaymentsController::class);
        Route::get('/payments/{payment}', ShowAdminPaymentController::class);
        Route::get('/payments/{payment}/documents/{document}/download', DownloadPaymentDocumentController::class)
            ->name('admin.payment-document.download');
        Route::get('/orders', ListAdminOrdersController::class);
        Route::get('/games/{game}/numbers', ListGameNumbersAdminController::class);
    });

Route::middleware('auth:sanctum')->prefix('me')->group(function (): void {
    Route::get('/reservations', ListMyReservationsController::class);
    Route::get('/orders', ListMyOrdersController::class);
    Route::get('/orders/{order}', ShowMyOrderController::class);
    Route::post('/orders/{order}/cancel', CancelOrderController::class);
    Route::get('/entries', ListMyEntriesController::class);
});
