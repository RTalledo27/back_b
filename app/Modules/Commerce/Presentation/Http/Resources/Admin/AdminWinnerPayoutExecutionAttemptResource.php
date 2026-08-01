<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Admin;

use App\Modules\Commerce\Domain\Models\WinnerPayoutExecutionAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WinnerPayoutExecutionAttempt $resource */
final class AdminWinnerPayoutExecutionAttemptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $attempt = $this->resource;

        return [
            'id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status->value,
            'destination' => $attempt->destination === null ? null : [
                'id' => $attempt->destination->id,
                'version' => $attempt->destination->version,
                'method' => $attempt->destination->method->value,
                'masked' => $attempt->destination->destination_masked,
            ],
            'external_reference' => $attempt->external_reference_masked,
            'failure_reason_code' => $attempt->failure_reason_code,
            'started_at' => $attempt->started_at?->utc()->toIso8601String(),
            'paid_at' => $attempt->paid_at?->utc()->toIso8601String(),
            'failed_at' => $attempt->failed_at?->utc()->toIso8601String(),
        ];
    }
}
