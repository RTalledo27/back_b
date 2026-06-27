<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Actions\ProcessWinnerPayoutAction;
use App\Modules\Commerce\Application\DTOs\ProcessWinnerPayoutData;
use App\Modules\Commerce\Presentation\Http\Requests\Admin\ProcessWinnerPayoutRequest;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\WinnerPayoutResource;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class ProcessWinnerPayoutController
{
    public function __invoke(
        ProcessWinnerPayoutRequest $request,
        Game $game,
        ProcessWinnerPayoutAction $action,
    ): Response {
        $actorUserId = (int) $request->user()?->getKey();
        $idempotencyKeyHash = hash('sha256', trim((string) $request->header('Idempotency-Key')));

        /** @var UploadedFile $file */
        $file = $request->file('document');
        $sha256 = hash_file('sha256', $file->getRealPath());
        $originalFilename = $file->getClientOriginalName();
        $mimeType = $file->getMimeType() ?? $file->getClientMimeType();
        $sizeBytes = (int) $file->getSize();

        $disk = 'winner_payouts';
        $storedPath = null;

        try {
            // Store file before transaction. If action fails, compensation deletes it.
            $storedPath = Storage::disk($disk)->putFile(
                'payouts/'.now()->format('Y/m/d'),
                $file,
            );

            if ($storedPath === false || $storedPath === null) {
                throw new \RuntimeException('Failed to store payout document.');
            }

            $data = new ProcessWinnerPayoutData(
                gameId: (string) $game->getKey(),
                actorUserId: $actorUserId,
                externalReference: $request->externalReference(),
                notes: $request->notes(),
                idempotencyKeyHash: $idempotencyKeyHash,
                documentDisk: $disk,
                documentPath: (string) $storedPath,
                documentOriginalFilename: $originalFilename,
                documentMimeType: $mimeType,
                documentSizeBytes: $sizeBytes,
                documentSha256: (string) $sha256,
            );

            $result = $action->execute($data);

            // Idempotent return: delete orphan file (document already in DB from first call)
            if ($result->wasAlreadyProcessed) {
                try {
                    Storage::disk($disk)->delete((string) $storedPath);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return (new WinnerPayoutResource($result))
                ->response()
                ->setStatusCode(Response::HTTP_OK);

        } catch (\Throwable $e) {
            // Compensation: delete stored file if upload succeeded but action failed
            if ($storedPath !== null) {
                try {
                    Storage::disk($disk)->delete((string) $storedPath);
                } catch (\Throwable $deleteException) {
                    report($deleteException);
                }
            }
            throw $e;
        }
    }
}
