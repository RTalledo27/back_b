<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Actions\CancelWinnerPayoutAction;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutCommandResult;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutTransitionData;
use App\Modules\Commerce\Application\Queries\GetWinnerPayoutQuery;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Presentation\Http\Requests\Admin\WinnerPayoutReasonRequest;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminWinnerPayoutResource;
use Illuminate\Support\Facades\Gate;

final class CancelWinnerPayoutController
{
    public function __invoke(WinnerPayoutReasonRequest $request, WinnerPayout $payout, CancelWinnerPayoutAction $action, GetWinnerPayoutQuery $query, IdempotentCommandExecutor $executor): AdminWinnerPayoutResource
    {
        Gate::authorize('cancel', $payout);
        $actor = (int) $request->user()?->getKey();
        $key = (string) $request->header('Idempotency-Key');
        $reason = $request->reasonCode();
        $context = IdempotencyContext::make($actor, $request->method(), $request->path(), $key, ['operation' => 'winner_payout.cancel', 'payout_id' => $payout->id, 'actor_user_id' => $actor, 'reason_code' => $reason]);
        /** @var WinnerPayoutCommandResult $result */
        $result = $executor->execute($context, fn (): WinnerPayoutCommandResult => $action->executeWithinTransaction(new WinnerPayoutTransitionData((string) $payout->id, $actor, hash('sha256', $key), $context->payloadSha256, $reason)), fn (array $payload): WinnerPayoutCommandResult => WinnerPayoutCommandResult::fromArray($payload));
        return new AdminWinnerPayoutResource($query->execute($result->payoutId));
    }
}
