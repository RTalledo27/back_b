<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Actions\UpdateWinnerPayoutDestinationAction;
use App\Modules\Commerce\Application\DTOs\UpdateWinnerPayoutDestinationData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutCommandResult;
use App\Modules\Commerce\Application\Queries\GetWinnerPayoutQuery;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Application\Support\WinnerPayoutDestinationFactory;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Presentation\Http\Requests\Admin\UpdateWinnerPayoutDestinationRequest;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminWinnerPayoutResource;
use Illuminate\Support\Facades\Gate;

final class UpdateWinnerPayoutDestinationController
{
    public function __invoke(UpdateWinnerPayoutDestinationRequest $request, WinnerPayout $payout, UpdateWinnerPayoutDestinationAction $action, GetWinnerPayoutQuery $query, WinnerPayoutDestinationFactory $destinations, IdempotentCommandExecutor $executor): AdminWinnerPayoutResource
    {
        Gate::authorize('update', $payout);
        $actor = (int) $request->user()?->getKey();
        $destination = $destinations->make($request->destination());
        $key = (string) $request->header('Idempotency-Key');
        $context = IdempotencyContext::make($actor, $request->method(), $request->path(), $key, ['operation' => 'winner_payout.destination.update', 'payout_id' => $payout->id, 'actor_user_id' => $actor, 'destination' => ['method' => $destination->method, 'payload' => $destination->payload]]);

        /** @var WinnerPayoutCommandResult $result */
        $result = $executor->execute($context, fn (): WinnerPayoutCommandResult => $action->executeWithinTransaction(new UpdateWinnerPayoutDestinationData((string) $payout->id, $actor, hash('sha256', $key), $context->payloadSha256, $destination)), fn (array $payload): WinnerPayoutCommandResult => WinnerPayoutCommandResult::fromArray($payload));

        return new AdminWinnerPayoutResource($query->execute($result->payoutId));
    }
}
