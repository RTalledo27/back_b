<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Actions\CloseGameFinancialAction;
use App\Modules\Commerce\Application\DTOs\FinancialCloseGameData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutSettlementCommandResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Domain\Models\GameFinancialClosure;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminGameFinancialClosureResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class CloseGameFinancialController
{
    public function __invoke(Request $request, Game $game, CloseGameFinancialAction $action, IdempotentCommandExecutor $executor): AdminGameFinancialClosureResource
    {
        Gate::authorize('financialClose', $game);
        $actor = (int) $request->user()?->getKey();
        $key = (string) $request->header('Idempotency-Key');
        $context = IdempotencyContext::make($actor, $request->method(), $request->path(), $key, ['operation' => 'game.financial_close', 'game_id' => (string) $game->id, 'actor_user_id' => $actor]);
        /** @var WinnerPayoutSettlementCommandResult $result */
        $result = $executor->execute($context, fn (): WinnerPayoutSettlementCommandResult => $action->executeWithinTransaction(new FinancialCloseGameData((string) $game->id, $actor, hash('sha256', $key), $context->payloadSha256)), fn (array $payload): WinnerPayoutSettlementCommandResult => WinnerPayoutSettlementCommandResult::fromArray($payload));

        return new AdminGameFinancialClosureResource(GameFinancialClosure::query()->findOrFail($result->resourceId));
    }
}
