<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Admin;

use App\Modules\Commerce\Application\DTOs\ProcessWinnerPayoutResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WinnerPayoutResource extends JsonResource
{
    public static $wrap = 'data';

    public function __construct(ProcessWinnerPayoutResult $result)
    {
        parent::__construct($result);
    }

    /**
     * Sensitive fields intentionally omitted from the response payload:
     * document disk, path, and sha256; payout idempotency and fingerprint hashes.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProcessWinnerPayoutResult $r */
        $r = $this->resource;

        return [
            'id' => $r->payoutId,
            'game_id' => $r->gameId,
            'game_winner_id' => $r->gameWinnerId,
            'user_id' => $r->winnerUserId,
            'amount_cents' => $r->amountCents,
            'currency' => $r->currency,
            'method' => $r->method,
            'external_reference' => $r->externalReference,
            'notes' => $r->notes,
            'processed_by_user_id' => $r->actorUserId,
            'processed_at' => $r->processedAt,
            'created_at' => $r->createdAt,
            'document' => [
                'id' => $r->documentId,
                'original_filename' => $r->documentOriginalFilename,
                'mime_type' => $r->documentMimeType,
                'size_bytes' => $r->documentSizeBytes,
                'created_at' => $r->documentCreatedAt,
            ],
            'was_already_processed' => $r->wasAlreadyProcessed,
        ];
    }
}
