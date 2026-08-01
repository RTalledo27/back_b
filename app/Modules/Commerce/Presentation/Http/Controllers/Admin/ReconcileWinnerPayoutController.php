<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Actions\ReconcileWinnerPayoutAction;
use App\Modules\Commerce\Application\DTOs\ReconcileWinnerPayoutData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutSettlementCommandResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutReconciliation;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Presentation\Http\Requests\Admin\ReconcileWinnerPayoutRequest;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminWinnerPayoutReconciliationResource;
use Illuminate\Support\Facades\Gate;

final class ReconcileWinnerPayoutController
{
    public function __invoke(ReconcileWinnerPayoutRequest $request, WinnerPayout $payout, ReconcileWinnerPayoutAction $action, IdempotentCommandExecutor $executor): AdminWinnerPayoutReconciliationResource
    {
        Gate::authorize('reconcile', $payout);
        $actor = (int) $request->user()?->getKey();
        $key = (string) $request->header('Idempotency-Key');
        $resultCode = (string) $request->string('result_code')->value();
        $reference = $request->input('reference');
        $notes = $request->input('notes');
        $context = IdempotencyContext::make($actor, $request->method(), $request->path(), $key, ['operation' => 'winner_payout.reconcile', 'payout_id' => (string) $payout->id, 'actor_user_id' => $actor, 'result_code' => $resultCode, 'reference' => $reference, 'notes' => $notes]);
        /** @var WinnerPayoutSettlementCommandResult $result */
        $result = $executor->execute($context, fn (): WinnerPayoutSettlementCommandResult => $action->executeWithinTransaction(new ReconcileWinnerPayoutData((string) $payout->id, $actor, $resultCode, is_string($reference) ? trim($reference) : null, is_string($notes) ? trim($notes) : null, hash('sha256', $key), $context->payloadSha256)), fn (array $payload): WinnerPayoutSettlementCommandResult => WinnerPayoutSettlementCommandResult::fromArray($payload));

        return new AdminWinnerPayoutReconciliationResource(WinnerPayoutReconciliation::query()->findOrFail($result->resourceId));
    }
}
