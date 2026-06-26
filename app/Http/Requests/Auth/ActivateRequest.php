<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\DTOs\Auth\ActivatePlayerData;
use Illuminate\Foundation\Http\FormRequest;

final class ActivateRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ];
    }

    public function toDto(): ActivatePlayerData
    {
        /** @var array{token:string,password:string} $validated */
        $validated = $this->validated();

        return new ActivatePlayerData(
            token: $validated['token'],
            password: $validated['password'],
        );
    }
}
