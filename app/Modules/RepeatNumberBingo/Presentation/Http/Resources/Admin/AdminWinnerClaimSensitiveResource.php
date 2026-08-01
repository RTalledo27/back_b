<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin;

use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WinnerClaim $resource */
final class AdminWinnerClaimSensitiveResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $claim = $this->resource;
        $profile = $claim->identityProfile;

        return [
            'claim' => (new AdminWinnerClaimResource($claim))->resolve(),
            'winner' => [
                'id' => $claim->winner?->id,
                'name' => $claim->winner?->name,
                'email' => $claim->winner?->email,
            ],
            'identity_profile' => $profile === null ? null : [
                'document_type' => $profile->document_type,
                'legal_name' => $profile->legal_name_encrypted,
                'document_number_masked' => $profile->document_number_masked,
                'accepted_prize_terms_at' => $profile->accepted_prize_terms_at?->utc()->toIso8601String(),
                'consented_identity_processing_at' => $profile->consented_identity_processing_at?->utc()->toIso8601String(),
            ],
            'documents' => $claim->documents->map(static fn ($document): array => [
                'id' => $document->id,
                'document_type' => $document->document_type,
                'mime_type' => $document->mime_type,
                'size_bytes' => (int) $document->size_bytes,
                'created_at' => $document->created_at?->utc()->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
