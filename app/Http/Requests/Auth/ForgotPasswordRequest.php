<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\DTOs\Auth\RegisterPlayerData;
use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge([
                'email' => RegisterPlayerData::normalizeEmail((string) $this->input('email')),
            ]);
        }
    }

    public function normalizedEmail(): string
    {
        /** @var array{email:string} $validated */
        $validated = $this->validated();

        return $validated['email'];
    }
}
