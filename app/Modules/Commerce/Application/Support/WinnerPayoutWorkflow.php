<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Support;

use App\Modules\Commerce\Application\DTOs\WinnerPayoutCommandResult;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class WinnerPayoutWorkflow
{
    public function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Winner payout actions require an active database transaction.');
        }
    }

    public function lockPayout(string $payoutId): WinnerPayout
    {
        return WinnerPayout::query()->whereKey($payoutId)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, scalar|null> $safeMetadata */
    public function recordEvent(
        WinnerPayout $payout,
        WinnerPayoutEventType $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?int $actorUserId,
        string $actorType,
        ?string $reasonCode = null,
        array $safeMetadata = [],
    ): void {
        $occurredAt = now();

        WinnerPayoutEvent::create([
            'winner_payout_id' => $payout->id,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actorUserId,
            'actor_type' => $actorType,
            'reason_code' => $reasonCode,
            'safe_metadata' => $safeMetadata,
            'correlation_id' => (string) Str::uuid7(),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
    }

    public function result(
        WinnerPayout $payout,
        bool $wasTransitionApplied = true,
        ?string $attemptId = null,
        ?string $documentId = null,
    ): WinnerPayoutCommandResult {
        return new WinnerPayoutCommandResult(
            payoutId: (string) $payout->id,
            status: $payout->status->value,
            wasTransitionApplied: $wasTransitionApplied,
            attemptId: $attemptId,
            documentId: $documentId,
        );
    }
}
