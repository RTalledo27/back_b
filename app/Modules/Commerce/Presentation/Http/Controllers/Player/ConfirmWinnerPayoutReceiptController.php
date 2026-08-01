<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Player;

use App\Modules\Commerce\Application\Actions\ConfirmWinnerPayoutReceiptAction;
use App\Modules\Commerce\Application\DTOs\ConfirmWinnerPayoutReceiptData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutSettlementCommandResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Presentation\Http\Requests\Player\ConfirmWinnerPayoutReceiptRequest;
use App\Modules\RepeatNumberBingo\Application\Queries\GetPlayerWinnerClaimQuery;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Player\PlayerWinnerClaimResource;

final class ConfirmWinnerPayoutReceiptController
{
    public function __invoke(ConfirmWinnerPayoutReceiptRequest $request, GameWinner $winner, ConfirmWinnerPayoutReceiptAction $action, GetPlayerWinnerClaimQuery $query, IdempotentCommandExecutor $executor): PlayerWinnerClaimResource
    {
        $actor = (int) $request->user()?->getKey();
        $key = (string) $request->header('Idempotency-Key');
        $context = IdempotencyContext::make($actor, $request->method(), $request->path(), $key, ['operation' => 'winner_payout.confirm_receipt', 'winner_id' => (string) $winner->id, 'actor_user_id' => $actor, 'accepted' => $request->boolean('accepted')]);
        /** @var WinnerPayoutSettlementCommandResult $result */
        $result = $executor->execute(
            $context,
            fn (): WinnerPayoutSettlementCommandResult => $action->executeWithinTransaction(new ConfirmWinnerPayoutReceiptData((string) $winner->id, $actor, hash('sha256', $key), $context->payloadSha256)),
            fn (array $payload): WinnerPayoutSettlementCommandResult => WinnerPayoutSettlementCommandResult::fromArray($payload),
        );

        return new PlayerWinnerClaimResource($query->findForUser((string) $winner->id, $actor));
    }
}
