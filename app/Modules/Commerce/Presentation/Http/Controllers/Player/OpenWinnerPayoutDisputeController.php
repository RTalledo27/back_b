<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Player;

use App\Modules\Commerce\Application\Actions\OpenWinnerPayoutDisputeAction;
use App\Modules\Commerce\Application\DTOs\OpenWinnerPayoutDisputeData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutSettlementCommandResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Presentation\Http\Requests\Player\OpenWinnerPayoutDisputeRequest;
use App\Modules\RepeatNumberBingo\Application\Queries\GetPlayerWinnerClaimQuery;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Player\PlayerWinnerClaimResource;

final class OpenWinnerPayoutDisputeController
{
    public function __invoke(OpenWinnerPayoutDisputeRequest $request, GameWinner $winner, OpenWinnerPayoutDisputeAction $action, GetPlayerWinnerClaimQuery $query, IdempotentCommandExecutor $executor): PlayerWinnerClaimResource
    {
        $actor = (int) $request->user()?->getKey();
        $key = (string) $request->header('Idempotency-Key');
        $reason = (string) $request->string('reason_code')->value();
        $description = $request->input('description');
        $context = IdempotencyContext::make($actor, $request->method(), $request->path(), $key, ['operation' => 'winner_payout.open_dispute', 'winner_id' => (string) $winner->id, 'actor_user_id' => $actor, 'reason_code' => $reason, 'description' => $description]);
        /** @var WinnerPayoutSettlementCommandResult $result */
        $result = $executor->execute(
            $context,
            fn (): WinnerPayoutSettlementCommandResult => $action->executeWithinTransaction(new OpenWinnerPayoutDisputeData((string) $winner->id, $actor, $reason, is_string($description) ? trim($description) : null, hash('sha256', $key), $context->payloadSha256)),
            fn (array $payload): WinnerPayoutSettlementCommandResult => WinnerPayoutSettlementCommandResult::fromArray($payload),
        );

        return new PlayerWinnerClaimResource($query->findForUser((string) $winner->id, $actor));
    }
}
