<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Player;

use App\Modules\Commerce\Domain\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

final class SubmitPaymentEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Order|null $order */
        $order = $this->route('order');

        return $order !== null && Gate::allows('submitEvidence', $order);
    }

    /**
     * `mimetypes` validates against the server-side detected MIME (finfo),
     * not the client-supplied Content-Type. This is the first-pass guard;
     * PaymentEvidenceStorage::analyse() re-verifies on the temp path.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('commerce.evidence.max_size_kb', 5120);

        return [
            'evidence' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                'max:'.$maxKb,
            ],
        ];
    }

    public function uploadedFile(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('evidence');

        return $file;
    }
}
