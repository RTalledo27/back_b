<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class GamePrizeFundingDocumentStorage
{
    public function __construct(
        private readonly PaymentEvidenceStorage $analysis,
    ) {}

    public function analyse(UploadedFile $file): EvidenceAnalysis
    {
        $analysis = $this->analysis->analyse($file);
        $maxSizeBytes = ((int) config('commerce.prize_funding.max_size_kb', 5120)) * 1024;

        if ($analysis->sizeBytes > $maxSizeBytes) {
            throw new \RuntimeException('Prize funding document exceeds the configured size limit.');
        }

        return $analysis;
    }

    /** @return array{documentId: string, disk: string, path: string, originalFilename: string} */
    public function store(UploadedFile $file, string $gameId, EvidenceAnalysis $analysis): array
    {
        $documentId = (string) Str::uuid7();
        $disk = (string) config('commerce.prize_funding.disk', 'game_prize_fundings');
        $filename = $documentId.'.'.$analysis->extension;
        $directory = 'games/'.$gameId;
        $path = $directory.'/'.$filename;

        Storage::disk($disk)->putFileAs($directory, $file, $filename);

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
