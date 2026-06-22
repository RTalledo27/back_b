<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Admin;

use App\Modules\Commerce\Domain\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ApprovePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Payment|null $payment */
        $payment = $this->route('payment');

        return $payment !== null && Gate::allows('approve', $payment);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function notes(): ?string
    {
        /** @var string|null $notes */
        $notes = $this->input('notes');

        return $notes;
    }
}
