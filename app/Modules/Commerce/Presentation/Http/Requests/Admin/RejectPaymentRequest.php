<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Admin;

use App\Modules\Commerce\Domain\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class RejectPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Payment|null $payment */
        $payment = $this->route('payment');

        return $payment !== null && Gate::allows('reject', $payment);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->input('reason');
    }
}
