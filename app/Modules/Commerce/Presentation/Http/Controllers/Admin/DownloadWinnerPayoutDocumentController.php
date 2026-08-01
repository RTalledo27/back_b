<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class DownloadWinnerPayoutDocumentController
{
    public function __invoke(WinnerPayout $payout, WinnerPayoutDocument $payoutDocument): Response
    {
        Gate::authorize('viewDocument', $payout);

        abort_unless((string) $payoutDocument->payout_id === (string) $payout->id, 404);

        abort_unless(Storage::disk($payoutDocument->disk)->exists($payoutDocument->path), 404);

        $response = Storage::disk($payoutDocument->disk)->download($payoutDocument->path, $payoutDocument->original_filename, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
