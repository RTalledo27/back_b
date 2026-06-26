<?php

use App\Exceptions\Auth\InvalidActivationTokenException;
use App\Exceptions\Auth\SocialAuthException;
use App\Http\Middleware\EnsureIdempotencyKeyHeader;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Modules\Commerce\Domain\Exceptions\EvidenceRejected;
use App\Modules\Commerce\Domain\Exceptions\EvidenceValidationException;
use App\Modules\Commerce\Domain\Exceptions\GameNotAcceptingPayments;
use App\Modules\Commerce\Domain\Exceptions\GameNotInSalesOpen;
use App\Modules\Commerce\Domain\Exceptions\GameNumbersDoNotBelongToGame;
use App\Modules\Commerce\Domain\Exceptions\IdempotencyInProgress;
use App\Modules\Commerce\Domain\Exceptions\IdempotencyKeyMismatch;
use App\Modules\Commerce\Domain\Exceptions\InvalidOrderTransition;
use App\Modules\Commerce\Domain\Exceptions\InvalidPaymentTransition;
use App\Modules\Commerce\Domain\Exceptions\NumberNotAvailableForReservation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\DrawnNumberOutOfRange;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameAlreadyCompleted;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameEngineAutomationActive;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameEngineAutomationInactive;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameHasNoScheduledStart;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameNotReadyForStart;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameParticipationIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameStartTooEarly;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidDrawCommandId;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameConfiguration;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameEngineConfiguration;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameTransition;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\RebuildIntegrityViolation;
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
            'idempotent' => EnsureIdempotencyKeyHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (SocialAuthException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => $e->errorCode,
                ], $e->httpStatus);
            }
        });

        $exceptions->render(function (InvalidActivationTokenException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'invalid_activation_token',
                    'reason' => $e->reason,
                ], 422);
            }
        });

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

        $exceptions->render(function (GameNotInSalesOpen $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'game_not_in_sales_open',
                ], 422);
            }
        });

        $exceptions->render(function (GameNumbersDoNotBelongToGame $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'game_numbers_do_not_belong_to_game',
                ], 422);
            }
        });

        $exceptions->render(function (NumberNotAvailableForReservation $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'number_not_available_for_reservation',
                ], 422);
            }
        });

        $exceptions->render(function (InvalidPaymentTransition $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'invalid_payment_transition',
                ], 422);
            }
        });

        $exceptions->render(function (GameNotAcceptingPayments $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'game_not_accepting_payments',
                    'context' => [
                        'game_id' => $e->gameId,
                        'current_status' => $e->currentStatus,
                        'allowed_statuses' => $e->allowedStatuses,
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (InvalidOrderTransition $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'invalid_order_transition',
                ], 422);
            }
        });

        $exceptions->render(function (EvidenceRejected $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'evidence_rejected',
                ], 422);
            }
        });

        $exceptions->render(function (EvidenceValidationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'evidence_validation_failed',
                ], 422);
            }
        });

        $exceptions->render(function (IdempotencyKeyMismatch $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'idempotency_key_mismatch',
                ], 409);
            }
        });

        $exceptions->render(function (IdempotencyInProgress $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'idempotency_in_progress',
                ], 425);
            }
        });

        // Phase 3.8 — engine error mapping.

        $exceptions->render(function (GameAlreadyCompleted $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'game_already_completed',
                ], 422);
            }
        });

        $exceptions->render(function (GameHasNoScheduledStart $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'game_has_no_scheduled_start',
                ], 422);
            }
        });

        $exceptions->render(function (GameStartTooEarly $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'game_start_too_early',
                ], 422);
            }
        });

        $exceptions->render(function (GameNotReadyForStart $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'game_not_ready_for_start',
                    'reasons' => $e->reasons,
                ], 422);
            }
        });

        $exceptions->render(function (InvalidDrawCommandId $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'invalid_draw_command_id',
                ], 422);
            }
        });

        $exceptions->render(function (GameLifecycleIntegrityViolation $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Game lifecycle integrity check failed.',
                    'error' => 'game_lifecycle_integrity_violation',
                ], 409);
            }
        });

        $exceptions->render(function (GameParticipationIntegrityViolation $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Game participation integrity check failed.',
                    'error' => 'game_participation_integrity_violation',
                ], 409);
            }
        });

        $exceptions->render(function (RebuildIntegrityViolation $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Counters rebuild integrity check failed.',
                    'error' => 'rebuild_integrity_violation',
                ], 409);
            }
        });

        $exceptions->render(function (GameEngineAutomationActive $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'game_engine_automation_active',
                ], 422);
            }
        });

        $exceptions->render(function (GameEngineAutomationInactive $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'game_engine_automation_inactive',
                ], 422);
            }
        });

        $exceptions->render(function (InvalidGameEngineConfiguration $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'invalid_game_engine_configuration',
                ], 422);
            }
        });

        $exceptions->render(function (DrawnNumberOutOfRange $e, Request $request) {
            if ($request->expectsJson()) {
                report($e);

                return response()->json([
                    'message' => 'Internal draw engine error.',
                    'error' => 'internal_engine_error',
                ], 500);
            }
        });
    })->create();
