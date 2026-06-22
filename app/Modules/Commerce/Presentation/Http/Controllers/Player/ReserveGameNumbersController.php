<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Player;

use App\Modules\Commerce\Application\Actions\ReserveGameNumbersAction;
use App\Modules\Commerce\Application\DTOs\ReserveGameNumbersData;
use App\Modules\Commerce\Application\DTOs\ReserveGameNumbersResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Presentation\Http\Requests\Player\ReserveGameNumbersRequest;
use App\Modules\Commerce\Presentation\Http\Resources\ReserveGameNumbersResource;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Symfony\Component\HttpFoundation\Response;

final class ReserveGameNumbersController
{
    public function __invoke(
        ReserveGameNumbersRequest $request,
        Game $game,
        ReserveGameNumbersAction $action,
        IdempotentCommandExecutor $executor,
    ): Response {
        $user = $request->user();
        $requestedIds = $request->gameNumberIds();

        // Sort once: same ids in any order produce the same idempotency hash
        // AND the Action locks in the same order regardless of input order.
        $sortedIds = $requestedIds;
        sort($sortedIds, SORT_STRING);
        $sortedIds = array_values($sortedIds);

        $data = new ReserveGameNumbersData(
            gameId: $game->getKey(),
            userId: $user->getKey(),
            gameNumberIds: $sortedIds,
        );

        $context = IdempotencyContext::make(
            userId: $user->getKey(),
            method: $request->method(),
            path: $request->path(),
            key: (string) $request->header('Idempotency-Key'),
            payloadComponents: [
                'game_id' => $game->getKey(),
                'game_number_ids' => $sortedIds,
            ],
        );

        $result = $executor->execute(
            context: $context,
            command: fn (): ReserveGameNumbersResult => $action->executeWithinTransaction($data),
            hydrate: fn (array $payload): ReserveGameNumbersResult => ReserveGameNumbersResult::fromArray($payload),
        );

        return (new ReserveGameNumbersResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
