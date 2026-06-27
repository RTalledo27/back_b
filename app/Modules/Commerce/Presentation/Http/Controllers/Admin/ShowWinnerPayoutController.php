<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\DTOs\ProcessWinnerPayoutResult;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutNotFound;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDocument;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\WinnerPayoutResource;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ShowWinnerPayoutController
{
    public function __invoke(Request $request, Game $game): Response
    {
        $payout = WinnerPayout::query()->where('game_id', $game->getKey())->first();

        if ($payout === null) {
            throw WinnerPayoutNotFound::forGame((string) $game->getKey());
        }

        $document = WinnerPayoutDocument::query()->where('payout_id', $payout->id)->firstOrFail();

        $result = new ProcessWinnerPayoutResult(
            payoutId: $payout->id,
            gameWinnerId: $payout->game_winner_id,
            gameId: $payout->game_id,
            winnerUserId: $payout->user_id,
            actorUserId: $payout->processed_by_user_id,
            amountCents: $payout->amount_cents,
            currency: $payout->currency,
            method: $payout->method,
            externalReference: $payout->external_reference,
            notes: $payout->notes,
            processedAt: $payout->processed_at->toIso8601String(),
            createdAt: $payout->created_at->toIso8601String(),
            documentId: $document->id,
            documentOriginalFilename: $document->original_filename,
            documentMimeType: $document->mime_type,
            documentSizeBytes: $document->size_bytes,
            documentCreatedAt: $document->created_at->toIso8601String(),
            wasAlreadyProcessed: true,
        );

        return (new WinnerPayoutResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
