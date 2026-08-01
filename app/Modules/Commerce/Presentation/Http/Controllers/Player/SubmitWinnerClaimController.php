<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Player;

use App\Modules\Commerce\Application\Support\SubmitWinnerClaimOrchestrator;
use App\Modules\RepeatNumberBingo\Application\DTOs\SubmitWinnerClaimData;
use App\Modules\RepeatNumberBingo\Application\Queries\GetPlayerWinnerClaimQuery;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Player\SubmitWinnerClaimRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Player\PlayerWinnerClaimResource;
use Illuminate\Http\UploadedFile;

final class SubmitWinnerClaimController
{
    public function __invoke(
        SubmitWinnerClaimRequest $request,
        GameWinner $winner,
        SubmitWinnerClaimOrchestrator $orchestrator,
        GetPlayerWinnerClaimQuery $query,
    ): PlayerWinnerClaimResource {
        /** @var UploadedFile $front */
        $front = $request->file('identity_front');
        $files = ['identity_front' => $front];

        foreach (['identity_back', 'identity_additional'] as $field) {
            $file = $request->file($field);
            if ($file instanceof UploadedFile) {
                $files[$field] = $file;
            }
        }

        $result = $orchestrator->handle(
            data: new SubmitWinnerClaimData(
                winnerId: (string) $winner->id,
                userId: (int) $request->user()?->getKey(),
                legalName: (string) $request->string('legal_name'),
                documentType: (string) $request->string('document_type'),
                documentNumber: (string) $request->string('document_number'),
                acceptedPrizeTerms: $request->boolean('accepted_prize_terms'),
                consentedIdentityProcessing: $request->boolean('consented_identity_processing'),
                documents: [],
            ),
            files: $files,
            idempotencyKey: (string) $request->header('Idempotency-Key'),
            requestMethod: $request->method(),
            requestPath: $request->path(),
        );

        $claim = $query->findForUser((string) $winner->id, (int) $request->user()?->getKey());

        return new PlayerWinnerClaimResource($claim);
    }
}
