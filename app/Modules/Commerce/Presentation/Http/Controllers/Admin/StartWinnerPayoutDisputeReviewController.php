<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Actions\StartWinnerPayoutDisputeReviewAction;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutSettlementCommandResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminWinnerPayoutDisputeResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class StartWinnerPayoutDisputeReviewController
{
    public function __invoke(Request $request, WinnerPayoutDispute $dispute, StartWinnerPayoutDisputeReviewAction $action, IdempotentCommandExecutor $executor): AdminWinnerPayoutDisputeResource
    {
        Gate::authorize('startReview', $dispute);
        $actor = (int) $request->user()?->getKey();
        $key = (string) $request->header('Idempotency-Key');
        $context = IdempotencyContext::make($actor, $request->method(), $request->path(), $key, ['operation' => 'winner_payout_dispute.start_review', 'dispute_id' => (string) $dispute->id, 'actor_user_id' => $actor]);
        /** @var WinnerPayoutSettlementCommandResult $result */
        $result = $executor->execute($context, fn (): WinnerPayoutSettlementCommandResult => $action->executeWithinTransaction((string) $dispute->id, $actor), fn (array $payload): WinnerPayoutSettlementCommandResult => WinnerPayoutSettlementCommandResult::fromArray($payload));

        return new AdminWinnerPayoutDisputeResource(WinnerPayoutDispute::query()->findOrFail($result->resourceId));
    }
}
