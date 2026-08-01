<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class RecordGamePrizeFundingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('recordPrizeFunding', $this->route('game'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => [
                'required',
                'string',
                'min:16',
                'max:80',
                'regex:/^[A-Za-z0-9_-]+$/',
            ],
            'document' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:'.(int) config('commerce.prize_funding.max_size_kb', 5120),
            ],
        ];
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->input('idempotency_key'));
    }
}
