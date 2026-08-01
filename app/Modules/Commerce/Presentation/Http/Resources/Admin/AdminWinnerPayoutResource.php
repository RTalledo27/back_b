<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Admin;

use App\Modules\Commerce\Domain\Models\WinnerPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WinnerPayout $resource */
class AdminWinnerPayoutResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payout = $this->resource;

        return [
            'id' => $payout->id,
            'game_id' => $payout->game_id,
            'game_winner_id' => $payout->game_winner_id,
            'winner_claim_id' => $payout->winner_claim_id,
            'status' => $payout->status->value,
            'amount_cents' => $payout->amount_cents,
            'currency' => $payout->currency,
            'method' => $payout->method,
            'destination' => $payout->currentDestination === null ? null : [
                'id' => $payout->currentDestination->id,
                'version' => $payout->currentDestination->version,
                'method' => $payout->currentDestination->method->value,
                'masked' => $payout->currentDestination->destination_masked,
            ],
            'created_by_user_id' => $payout->created_by_user_id,
            'submitted_by_user_id' => $payout->submitted_by_user_id,
            'approved_by_user_id' => $payout->approved_by_user_id,
            'executed_by_user_id' => $payout->executed_by_user_id,
            'external_reference' => $this->maskedExternalReference($payout),
            'failure_reason_code' => $payout->failure_reason_code,
            'cancellation_reason_code' => $payout->cancellation_reason_code,
            'created_at' => $payout->created_at?->utc()->toIso8601String(),
            'submitted_at' => $payout->submitted_at?->utc()->toIso8601String(),
            'approved_at' => $payout->approved_at?->utc()->toIso8601String(),
            'processing_at' => $payout->processing_at?->utc()->toIso8601String(),
            'paid_at' => $payout->paid_at?->utc()->toIso8601String(),
            'failed_at' => $payout->failed_at?->utc()->toIso8601String(),
            'cancelled_at' => $payout->cancelled_at?->utc()->toIso8601String(),
            'execution_attempts' => AdminWinnerPayoutExecutionAttemptResource::collection($payout->executionAttempts),
            'documents' => $payout->documents->map(fn ($document): array => [
                'id' => $document->id,
                'document_type' => $document->document_type,
                'execution_attempt_id' => $document->execution_attempt_id,
                'mime_type' => $document->mime_type,
                'size_bytes' => $document->size_bytes,
                'created_at' => $document->created_at?->utc()->toIso8601String(),
            ])->values(),
        ];
    }

    private function maskedExternalReference(WinnerPayout $payout): ?string
    {
        if ($payout->status->value !== 'legacy_registered') {
            return $payout->currentExecutionAttempt?->external_reference_masked;
        }

        $reference = trim((string) $payout->external_reference);

        return $reference === '' ? null : (strlen($reference) <= 4 ? '****' : '****'.substr($reference, -4));
    }
}
