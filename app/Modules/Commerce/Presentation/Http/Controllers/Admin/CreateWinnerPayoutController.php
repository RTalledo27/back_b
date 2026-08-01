<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Actions\CreateWinnerPayoutAction;
use App\Modules\Commerce\Application\DTOs\CreateWinnerPayoutData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutCommandResult;
use App\Modules\Commerce\Application\Queries\GetWinnerPayoutQuery;
use App\Modules\Commerce\Application\Support\WinnerPayoutDestinationFactory;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Presentation\Http\Requests\Admin\CreateWinnerPayoutRequest;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminWinnerPayoutResource;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class CreateWinnerPayoutController
{
    public function __invoke(
        CreateWinnerPayoutRequest $request,
        Game $game,
        CreateWinnerPayoutAction $action,
        GetWinnerPayoutQuery $query,
        WinnerPayoutDestinationFactory $destinations,
        IdempotentCommandExecutor $executor,
    ): Response {
        Gate::authorize('create', WinnerPayout::class);
        $actor = (int) $request->user()?->getKey();
        $destination = $destinations->make($request->destination());
        $key = (string) $request->header('Idempotency-Key');
        $context = IdempotencyContext::make($actor, $request->method(), $request->path(), $key, [
            'operation' => 'winner_payout.create',
            'game_id' => (string) $game->getKey(),
            'actor_user_id' => $actor,
            'destination' => ['method' => $destination->method, 'payload' => $destination->payload],
        ]);

        /** @var WinnerPayoutCommandResult $result */
        $result = $executor->execute(
            context: $context,
            command: fn (): WinnerPayoutCommandResult => $action->executeWithinTransaction(new CreateWinnerPayoutData(
                gameId: (string) $game->getKey(),
                actorUserId: $actor,
                idempotencyKeyHash: hash('sha256', $key),
                requestFingerprint: $context->payloadSha256,
                destination: $destination,
            )),
            hydrate: fn (array $payload): WinnerPayoutCommandResult => WinnerPayoutCommandResult::fromArray($payload),
        );

        return (new AdminWinnerPayoutResource($query->execute($result->payoutId)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
