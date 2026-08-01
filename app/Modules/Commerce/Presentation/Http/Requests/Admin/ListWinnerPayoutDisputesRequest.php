<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Admin;

use App\Modules\Commerce\Domain\Enums\WinnerPayoutDisputeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListWinnerPayoutDisputesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(array_map(static fn (WinnerPayoutDisputeStatus $status): string => $status->value, WinnerPayoutDisputeStatus::cases()))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
