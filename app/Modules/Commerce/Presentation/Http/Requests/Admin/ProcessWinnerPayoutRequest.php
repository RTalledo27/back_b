<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class ProcessWinnerPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'external_reference' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function externalReference(): string
    {
        return (string) $this->string('external_reference')->trim()->value();
    }

    public function notes(): ?string
    {
        $notes = $this->input('notes');

        if ($notes === null) {
            return null;
        }

        $trimmed = trim((string) $notes);

        return $trimmed === '' ? null : $trimmed;
    }
}
