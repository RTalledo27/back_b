<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\DTOs\RecordGamePrizeFundingCommandResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Infrastructure\Storage\GamePrizeFundingDocumentStorage;
use App\Modules\RepeatNumberBingo\Application\Actions\RecordGamePrizeFundingAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\RecordGamePrizeFundingData;
use App\Modules\RepeatNumberBingo\Application\Queries\GetGamePrizeFundingQuery;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\RecordGamePrizeFundingRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminGamePrizeFundingResource;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class RecordGamePrizeFundingController
{
    public function __invoke(
        RecordGamePrizeFundingRequest $request,
        Game $game,
        RecordGamePrizeFundingAction $action,
        GetGamePrizeFundingQuery $query,
        GamePrizeFundingDocumentStorage $storage,
        IdempotentCommandExecutor $executor,
    ): Response {
        /** @var UploadedFile $file */
        $file = $request->file('document');
        $analysis = $storage->analyse($file);
        $stored = null;

        try {
            $stored = $storage->store($file, (string) $game->getKey(), $analysis);
            $context = IdempotencyContext::make(
                userId: (int) $request->user()?->getKey(),
                method: $request->method(),
                path: $request->path(),
                key: $request->idempotencyKey(),
                payloadComponents: [
                    'game_id' => $game->getKey(),
                    'file_sha256' => $analysis->sha256,
                    'mime_type' => $analysis->mimeType,
                    'size_bytes' => $analysis->sizeBytes,
                ],
            );

            /** @var RecordGamePrizeFundingCommandResult $commandResult */
            $commandResult = $executor->execute(
                context: $context,
                command: fn (): RecordGamePrizeFundingCommandResult => new RecordGamePrizeFundingCommandResult(
                    $action->executeWithinTransaction(
                        new RecordGamePrizeFundingData(
                            gameId: (string) $game->getKey(),
                            actorUserId: (int) $request->user()?->getKey(),
                            idempotencyKeyHash: hash('sha256', $request->idempotencyKey()),
                            requestFingerprint: $context->payloadSha256,
                            documentId: $stored['documentId'],
                            documentDisk: $stored['disk'],
                            documentPath: $stored['path'],
                            documentOriginalFilename: $stored['originalFilename'],
                            documentMimeType: $analysis->mimeType,
                            documentSizeBytes: $analysis->sizeBytes,
                            documentSha256: $analysis->sha256,
                        ),
                    ),
                ),
                hydrate: fn (array $payload): RecordGamePrizeFundingCommandResult => RecordGamePrizeFundingCommandResult::fromArray($payload),
            );

            if ($commandResult->funding->documentId !== $stored['documentId']) {
                $storage->delete($stored['disk'], $stored['path']);
            }

            return (new AdminGamePrizeFundingResource($query->execute((string) $game->getKey())))
                ->response()
                ->setStatusCode(Response::HTTP_OK);
        } catch (\Throwable $exception) {
            if ($stored !== null) {
                $storage->delete($stored['disk'], $stored['path']);
            }

            throw $exception;
        }
    }
}
