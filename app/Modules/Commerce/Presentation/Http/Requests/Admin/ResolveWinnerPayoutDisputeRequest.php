<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveWinnerPayoutDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution_code' => ['required', 'string', 'in:payment_confirmed,retry_required,corrective_action_required,claim_rejected,withdrawn_by_winner'],
            'reason_code' => ['required', 'string', 'max:64'],
        ];
    }
}
