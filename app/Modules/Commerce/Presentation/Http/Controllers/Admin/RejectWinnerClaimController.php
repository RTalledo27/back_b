<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\DTOs\WinnerClaimReviewCommandResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\RepeatNumberBingo\Application\Actions\RejectWinnerClaimAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\ReviewWinnerClaimData;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\RejectWinnerClaimRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminWinnerClaimSensitiveResource;
use Illuminate\Http\Request;

final class RejectWinnerClaimController
{
    public function __invoke(
        RejectWinnerClaimRequest $request,
        WinnerClaim $claim,
        RejectWinnerClaimAction $action,
        IdempotentCommandExecutor $executor,
    ): AdminWinnerClaimSensitiveResource {
        $this->execute($request, $claim, $action, $executor);

        return new AdminWinnerClaimSensitiveResource(
            $claim->fresh(['gameWinner.game', 'winner', 'identityProfile', 'documents']),
        );
    }

    private function execute(
        Request $request,
        WinnerClaim $claim,
        RejectWinnerClaimAction $action,
        IdempotentCommandExecutor $executor,
    ): void {
        $reasonCode = (string) $request->string('reason_code');
        $context = IdempotencyContext::make(
            userId: (int) $request->user()?->getKey(),
            method: $request->method(),
            path: $request->path(),
            key: (string) $request->header('Idempotency-Key'),
            payloadComponents: [
                'claim_id' => $claim->id,
                'operation' => 'reject',
                'reason_code' => $reasonCode,
            ],
        );

        $executor->execute(
            context: $context,
            command: fn (): WinnerClaimReviewCommandResult => new WinnerClaimReviewCommandResult(
                $action->executeWithinTransaction(new ReviewWinnerClaimData(
                    claimId: (string) $claim->id,
                    reviewerUserId: (int) $request->user()?->getKey(),
                    reasonCode: $reasonCode,
                )),
            ),
            hydrate: static fn (array $payload): WinnerClaimReviewCommandResult => WinnerClaimReviewCommandResult::fromArray($payload),
        );
    }
}
