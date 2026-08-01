<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Storage;

use App\Modules\Commerce\Domain\Exceptions\EvidenceValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class WinnerPayoutDocumentStorage
{
    public function analyse(UploadedFile $file): EvidenceAnalysis
    {
        $path = (string) $file->getRealPath();

        if ($path === '' || ! is_readable($path)) {
            throw new EvidenceValidationException('Uploaded payout document is not readable.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new EvidenceValidationException('Failed to inspect payout document MIME type.');
        }

        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        $mime = is_string($mime) ? $mime : '';
        $map = (array) config('commerce.winner_payout.mime_to_extension', []);

        if (! isset($map[$mime])) {
            throw EvidenceValidationException::unsupportedMime($mime);
        }

        $size = filesize($path);
        $sha256 = hash_file('sha256', $path);
        $maxBytes = (int) config('commerce.winner_payout.max_size_kb', 10240) * 1024;

        if ($size === false || $size < 1 || $size > $maxBytes || $sha256 === false) {
            throw new EvidenceValidationException('Payout document size or checksum is invalid.');
        }

        return new EvidenceAnalysis(
            sha256: $sha256,
            mimeType: $mime,
            sizeBytes: $size,
            extension: (string) $map[$mime],
        );
    }

    /** @return array{documentId: string, disk: string, path: string, originalFilename: string} */
    public function store(UploadedFile $file, string $payoutId, string $attemptId, EvidenceAnalysis $analysis): array
    {
        $documentId = (string) Str::uuid7();
        $disk = (string) config('commerce.winner_payout.disk', 'winner_payouts');
        $filename = $documentId.'.'.$analysis->extension;
        $directory = 'payouts/'.$payoutId.'/'.$attemptId;
        $path = $directory.'/'.$filename;

        if (! Storage::disk($disk)->putFileAs($directory, $file, $filename)) {
            throw new EvidenceValidationException('Failed to store payout document.');
        }

        return [
            'documentId' => $documentId,
            'disk' => $disk,
            'path' => $path,
            'originalFilename' => mb_substr(basename((string) $file->getClientOriginalName()), 0, 255),
        ];
    }

    public function delete(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
