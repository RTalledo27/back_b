<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Actions\SubmitWinnerPayoutForApprovalAction;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutCommandResult;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutTransitionData;
use App\Modules\Commerce\Application\Queries\GetWinnerPayoutQuery;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminWinnerPayoutResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class SubmitWinnerPayoutController
{
    public function __invoke(Request $request, WinnerPayout $payout, SubmitWinnerPayoutForApprovalAction $action, GetWinnerPayoutQuery $query, IdempotentCommandExecutor $executor): AdminWinnerPayoutResource
    {
        Gate::authorize('submit', $payout);
        return $this->run($request, $payout, $action, $query, $executor, 'winner_payout.submit');
    }

    private function run(Request $request, WinnerPayout $payout, SubmitWinnerPayoutForApprovalAction $action, GetWinnerPayoutQuery $query, IdempotentCommandExecutor $executor, string $operation): AdminWinnerPayoutResource
    {
        $actor = (int) $request->user()?->getKey();
        $key = (string) $request->header('Idempotency-Key');
        $context = IdempotencyContext::make($actor, $request->method(), $request->path(), $key, ['operation' => $operation, 'payout_id' => $payout->id, 'actor_user_id' => $actor]);
        /** @var WinnerPayoutCommandResult $result */
        $result = $executor->execute($context, fn (): WinnerPayoutCommandResult => $action->executeWithinTransaction(new WinnerPayoutTransitionData((string) $payout->id, $actor, hash('sha256', $key), $context->payloadSha256)), fn (array $payload): WinnerPayoutCommandResult => WinnerPayoutCommandResult::fromArray($payload));
        return new AdminWinnerPayoutResource($query->execute($result->payoutId));
    }
}
