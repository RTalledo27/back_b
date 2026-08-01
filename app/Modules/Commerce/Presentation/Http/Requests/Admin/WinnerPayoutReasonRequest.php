<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class WinnerPayoutReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason_code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/']];
    }

    public function reasonCode(): string
    {
        return trim((string) $this->string('reason_code')->value());
    }
}
