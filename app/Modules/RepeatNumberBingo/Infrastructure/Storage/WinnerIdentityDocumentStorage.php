<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Infrastructure\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class WinnerIdentityDocumentStorage
{
    public function analyse(UploadedFile $file): WinnerIdentityDocumentAnalysis
    {
        $path = (string) $file->getRealPath();
        if ($path === '' || ! is_readable($path)) {
            throw new RuntimeException('Uploaded identity document is not readable.');
        }

        $mime = $this->detectMimeType($path);
        $map = (array) config('winner_claim.identity.mime_to_extension', []);
        if (! isset($map[$mime])) {
            throw new RuntimeException('Uploaded identity document MIME type is not supported.');
        }

        $size = filesize($path);
        $sha256 = hash_file('sha256', $path);
        $maxBytes = (int) config('winner_claim.identity.max_size_kb', 5120) * 1024;

        if ($size === false || $size <= 0 || $size > $maxBytes || $sha256 === false) {
            throw new RuntimeException('Uploaded identity document size or hash is invalid.');
        }

        return new WinnerIdentityDocumentAnalysis(
            sha256: $sha256,
            mimeType: $mime,
            sizeBytes: $size,
            extension: (string) $map[$mime],
        );
    }

    /** @return array{documentId: string, disk: string, path: string} */
    public function store(
        UploadedFile $file,
        string $ownerReference,
        WinnerIdentityDocumentAnalysis $analysis,
    ): array {
        $disk = (string) config('winner_claim.identity.disk', 'winner_identity_documents');
        $documentId = (string) Str::uuid7();
        $filename = $documentId.'.'.$analysis->extension;
        $path = 'claims/'.$ownerReference.'/'.$filename;

        if (! Storage::disk($disk)->putFileAs('claims/'.$ownerReference, $file, $filename)) {
            throw new RuntimeException('Failed to store identity document.');
        }

        return [
            'documentId' => $documentId,
            'disk' => $disk,
            'path' => $path,
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

    private function detectMimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('Unable to inspect identity document MIME type.');
        }

        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        if ($mime === false || $mime === '') {
            throw new RuntimeException('Unable to inspect identity document MIME type.');
        }

        return (string) $mime;
    }
}
