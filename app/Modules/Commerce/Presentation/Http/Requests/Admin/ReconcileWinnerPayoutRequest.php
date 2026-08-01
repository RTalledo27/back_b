<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class ReconcileWinnerPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'result_code' => ['required', 'string', 'in:amount_and_reference_match,amount_mismatch,currency_mismatch,reference_mismatch,destination_mismatch,proof_missing,duplicate_reference,manual_verification_required'],
            'reference' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
