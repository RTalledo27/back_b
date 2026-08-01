<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Actions\ResolveWinnerPayoutDisputeAction;
use App\Modules\Commerce\Application\DTOs\ResolveWinnerPayoutDisputeData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutSettlementCommandResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Presentation\Http\Requests\Admin\ResolveWinnerPayoutDisputeRequest;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminWinnerPayoutDisputeResource;
use Illuminate\Support\Facades\Gate;

final class ResolveWinnerPayoutDisputeController
{
    public function __invoke(ResolveWinnerPayoutDisputeRequest $request, WinnerPayoutDispute $dispute, ResolveWinnerPayoutDisputeAction $action, IdempotentCommandExecutor $executor): AdminWinnerPayoutDisputeResource
    {
        Gate::authorize('resolve', $dispute);
        $actor = (int) $request->user()?->getKey();
        $key = (string) $request->header('Idempotency-Key');
        $resolution = (string) $request->string('resolution_code')->value();
        $reason = (string) $request->string('reason_code')->value();
        $context = IdempotencyContext::make($actor, $request->method(), $request->path(), $key, ['operation' => 'winner_payout_dispute.resolve', 'dispute_id' => (string) $dispute->id, 'actor_user_id' => $actor, 'resolution_code' => $resolution, 'reason_code' => $reason]);
        /** @var WinnerPayoutSettlementCommandResult $result */
        $result = $executor->execute($context, fn (): WinnerPayoutSettlementCommandResult => $action->executeWithinTransaction(new ResolveWinnerPayoutDisputeData((string) $dispute->id, $actor, $resolution, $reason, hash('sha256', $key), $context->payloadSha256)), fn (array $payload): WinnerPayoutSettlementCommandResult => WinnerPayoutSettlementCommandResult::fromArray($payload));

        return new AdminWinnerPayoutDisputeResource(WinnerPayoutDispute::query()->findOrFail($result->resourceId));
    }
}
