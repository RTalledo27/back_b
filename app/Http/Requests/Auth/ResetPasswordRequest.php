<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\DTOs\Auth\RegisterPlayerData;
use Illuminate\Foundation\Http\FormRequest;

final class ResetPasswordRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
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

    /**
     * Returns credentials array expected by the Laravel password broker.
     *
     * @return array{email:string,token:string,password:string,password_confirmation:string}
     */
    public function toCredentials(): array
    {
        /** @var array{email:string,token:string,password:string,password_confirmation:string} $validated */
        $validated = $this->validated();

        return $validated;
    }
}
