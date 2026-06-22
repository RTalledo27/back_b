<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources;

use App\Modules\Commerce\Application\DTOs\SubmitPaymentEvidenceResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public response shape. Deliberately omits internal storage fields
 * (disk, path) — those are private to the server.
 */
final class SubmitPaymentEvidenceResource extends JsonResource
{
    public static $wrap = 'data';

    public function __construct(SubmitPaymentEvidenceResult $result)
    {
        parent::__construct($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SubmitPaymentEvidenceResult $r */
        $r = $this->resource;

        return [
            'order' => [
                'id' => $r->orderId,
                'status' => $r->orderStatus,
            ],
            'payment' => [
                'id' => $r->paymentId,
                'status' => $r->paymentStatus,
                'submitted_at' => $r->submittedAt,
            ],
            'document' => [
                'id' => $r->documentId,
                'original_filename' => $r->originalFilename,
                'mime_type' => $r->mimeType,
                'size_bytes' => $r->sizeBytes,
                'sha256' => $r->sha256,
            ],
        ];
    }
}
