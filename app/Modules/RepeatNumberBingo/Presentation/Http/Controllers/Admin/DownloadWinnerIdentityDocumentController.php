<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerIdentityDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DownloadWinnerIdentityDocumentController
{
    public function __invoke(WinnerClaim $claim, WinnerIdentityDocument $document): Response
    {
        Gate::authorize('viewWinnerIdentityDocuments', $claim);

        if ((string) $document->winner_claim_id !== (string) $claim->id) {
            throw new NotFoundHttpException('winner_identity_document_not_found');
        }

        $stream = Storage::disk($document->disk)->readStream($document->path);
        if ($stream === false) {
            throw new NotFoundHttpException('winner_identity_document_not_found');
        }

        $extension = match ($document->mime_type) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        return response()->streamDownload(
            static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            'identity-document-'.$document->id.'.'.$extension,
            [
                'Content-Type' => $document->mime_type,
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
