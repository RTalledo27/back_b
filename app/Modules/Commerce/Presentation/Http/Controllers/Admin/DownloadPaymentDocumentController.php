<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PaymentDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams a payment evidence file to an authorised admin.
 *
 *  - Authorisation: PaymentPolicy::downloadDocument.
 *  - The document MUST belong to the payment in the route — cross-ID
 *    attempts return 404 without revealing the other document's
 *    existence.
 *  - `disk` and `path` come from the persisted PaymentDocument row,
 *    NEVER from the request. The storage path uses UUID v7 segments so
 *    no traversal vector exists.
 *  - If the underlying file is missing on disk, returns 404 (controlled).
 *  - Response headers force a private, no-cache, no-sniff download.
 */
final class DownloadPaymentDocumentController
{
    public function __invoke(
        Request $request,
        Payment $payment,
        PaymentDocument $document,
    ): Response {
        Gate::authorize('downloadDocument', $payment);

        if ($document->payment_id !== $payment->getKey()) {
            throw new NotFoundHttpException('Document not found.');
        }

        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->path)) {
            throw new NotFoundHttpException('Document file is missing.');
        }

        $response = $disk->download(
            $document->path,
            $document->original_filename,
            ['Content-Type' => $document->mime_type],
        );

        // Force private/no-store + nosniff *after* the disk builder so
        // they cannot be clobbered by Symfony's default cache headers.
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
