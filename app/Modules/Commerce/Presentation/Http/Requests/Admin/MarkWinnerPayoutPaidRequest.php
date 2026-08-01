<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class MarkWinnerPayoutPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'external_reference' => ['required', 'string', 'max:500'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function externalReference(): string
    {
        return trim((string) $this->string('external_reference')->value());
    }
}
