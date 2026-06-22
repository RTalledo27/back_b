<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Storage;

use App\Modules\Commerce\Application\DTOs\StoredEvidenceData;
use App\Modules\Commerce\Domain\Exceptions\EvidenceValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Private storage gateway for payment evidence.
 *
 *  - analyse() inspects the upload (server-side MIME, streaming SHA-256,
 *    real size) WITHOUT writing — used by the orchestrator to build the
 *    idempotency hash before the claim.
 *  - store() persists the file to the private disk at a deterministic
 *    path: {payment_id}/{document_uuid}.{ext}. Returns StoredEvidenceData.
 *  - delete() removes a previously stored file (compensation path).
 */
final class PaymentEvidenceStorage
{
    /**
     * Inspect an uploaded file. Computes SHA-256 via hash_file (streaming)
     * to avoid loading the entire payload in memory.
     */
    public function analyse(UploadedFile $file): EvidenceAnalysis
    {
        $tempPath = (string) $file->getRealPath();

        if ($tempPath === '' || ! is_readable($tempPath)) {
            throw new EvidenceValidationException('Uploaded file is not readable.');
        }

        $mime = $this->detectMimeType($tempPath);
        $extension = $this->mimeToExtension($mime);

        $sha256 = hash_file('sha256', $tempPath);

        if ($sha256 === false) {
            throw new EvidenceValidationException('Failed to compute SHA-256 of uploaded file.');
        }

        $size = filesize($tempPath);

        if ($size === false || $size === 0) {
            throw new EvidenceValidationException('Uploaded file is empty or unreadable.');
        }

        return new EvidenceAnalysis(
            sha256: $sha256,
            mimeType: $mime,
            sizeBytes: $size,
            extension: $extension,
        );
    }

    /**
     * Persist the uploaded file to the private disk. The Orchestrator must
     * have already validated/analysed the file. Returns the StoredEvidenceData
     * the business Action will receive.
     */
    public function store(
        UploadedFile $file,
        string $paymentId,
        EvidenceAnalysis $analysis,
    ): StoredEvidenceData {
        $documentId = (string) Str::uuid7();
        $disk = (string) config('commerce.evidence.disk', 'payment_evidences');
        $relativeDir = $paymentId;
        $filename = $documentId.'.'.$analysis->extension;
        $path = $relativeDir.'/'.$filename;

        Storage::disk($disk)->putFileAs($relativeDir, $file, $filename);

        $originalFilename = $this->safeOriginalFilename($file);

        return new StoredEvidenceData(
            documentId: $documentId,
            disk: $disk,
            path: $path,
            originalFilename: $originalFilename,
            detectedMimeType: $analysis->mimeType,
            sizeBytes: $analysis->sizeBytes,
            sha256: $analysis->sha256,
        );
    }

    /**
     * Compensation: best-effort delete. Never throws — by the time we
     * compensate, the caller is already in a failure path.
     */
    public function delete(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->delete($path);
        } catch (\Throwable) {
            return false;
        }
    }

    public function mimeToExtension(string $mime): string
    {
        /** @var array<string, string> $map */
        $map = (array) config('commerce.evidence.mime_to_extension', []);

        if (! isset($map[$mime])) {
            throw EvidenceValidationException::unsupportedMime($mime);
        }

        return (string) $map[$mime];
    }

    private function detectMimeType(string $tempPath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new EvidenceValidationException('Failed to open fileinfo for MIME detection.');
        }

        try {
            $mime = finfo_file($finfo, $tempPath);
        } finally {
            finfo_close($finfo);
        }

        if ($mime === false || $mime === '') {
            throw new EvidenceValidationException('Failed to detect MIME type of uploaded file.');
        }

        $mime = (string) $mime;

        // libmagic on some Windows builds returns "application/octet-stream"
        // or "image/x-riff" instead of "image/webp" for WebP files. Verify
        // the RIFF/WEBP signature explicitly so detection is deterministic.
        if ($mime !== 'image/webp' && $this->hasWebPSignature($tempPath)) {
            return 'image/webp';
        }

        return $mime;
    }

    /**
     * WebP container layout (RFC 6386 §1):
     *   bytes  0..3  = "RIFF"
     *   bytes  4..7  = little-endian payload size (ignored here)
     *   bytes  8..11 = "WEBP"
     */
    private function hasWebPSignature(string $tempPath): bool
    {
        $fh = @fopen($tempPath, 'rb');

        if ($fh === false) {
            return false;
        }

        try {
            $header = fread($fh, 12);
        } finally {
            fclose($fh);
        }

        if ($header === false || strlen($header) < 12) {
            return false;
        }

        return substr($header, 0, 4) === 'RIFF'
            && substr($header, 8, 4) === 'WEBP';
    }

    private function safeOriginalFilename(UploadedFile $file): string
    {
        $raw = (string) $file->getClientOriginalName();
        $base = basename($raw);

        return mb_substr($base, 0, 255);
    }
}
