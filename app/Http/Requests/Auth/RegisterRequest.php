<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\DTOs\Auth\RegisterPlayerData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            'role' => ['prohibited'],
            'permissions' => ['prohibited'],
            'abilities' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $updates = [];

        if (is_string($this->input('name'))) {
            $updates['name'] = trim((string) $this->input('name'));
        }

        if (is_string($this->input('email'))) {
            $updates['email'] = RegisterPlayerData::normalizeEmail((string) $this->input('email'));
        }

        if ($updates !== []) {
            $this->merge($updates);
        }
    }

    public function toDto(): RegisterPlayerData
    {
        /** @var array{name:string,email:string,password:string} $validated */
        $validated = $this->validated();

        return RegisterPlayerData::fromArray($validated);
    }
}
