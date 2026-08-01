<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\DTOs\WinnerClaimReviewCommandResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\RepeatNumberBingo\Application\Actions\VerifyWinnerClaimAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\ReviewWinnerClaimData;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\VerifyWinnerClaimRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminWinnerClaimSensitiveResource;
use Illuminate\Http\Request;

final class VerifyWinnerClaimController
{
    public function __invoke(
        VerifyWinnerClaimRequest $request,
        WinnerClaim $claim,
        VerifyWinnerClaimAction $action,
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
        VerifyWinnerClaimAction $action,
        IdempotentCommandExecutor $executor,
    ): void {
        $context = IdempotencyContext::make(
            userId: (int) $request->user()?->getKey(),
            method: $request->method(),
            path: $request->path(),
            key: (string) $request->header('Idempotency-Key'),
            payloadComponents: ['claim_id' => $claim->id, 'operation' => 'verify'],
        );

        $executor->execute(
            context: $context,
            command: fn (): WinnerClaimReviewCommandResult => new WinnerClaimReviewCommandResult(
                $action->executeWithinTransaction(new ReviewWinnerClaimData(
                    claimId: (string) $claim->id,
                    reviewerUserId: (int) $request->user()?->getKey(),
                )),
            ),
            hydrate: static fn (array $payload): WinnerClaimReviewCommandResult => WinnerClaimReviewCommandResult::fromArray($payload),
        );
    }
}
