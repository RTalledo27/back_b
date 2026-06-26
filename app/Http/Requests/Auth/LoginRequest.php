<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\DTOs\Auth\LoginCredentialsData;
use App\DTOs\Auth\RegisterPlayerData;
use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
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
            'password' => ['required', 'string'],
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

    public function toDto(): LoginCredentialsData
    {
        /** @var array{email:string,password:string} $validated */
        $validated = $this->validated();

        return LoginCredentialsData::fromArray($validated);
    }
}
