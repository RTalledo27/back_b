<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTOs\Auth\CreatePlayerData;
use Illuminate\Foundation\Http\FormRequest;

final class CreatePlayerRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['prohibited'],
            'permissions' => ['prohibited'],
            'abilities' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $updates = [];

        if (is_string($this->input('name'))) {
            $updates['name'] = trim((string) $this->input('name'));
        }

        if (is_string($this->input('email'))) {
            $updates['email'] = CreatePlayerData::normalizeEmail((string) $this->input('email'));
        }

        if ($updates !== []) {
            $this->merge($updates);
        }
    }

    public function toDto(): CreatePlayerData
    {
        /** @var array{name:string,email:string} $validated */
        $validated = $this->validated();

        return new CreatePlayerData(
            name: $validated['name'],
            email: $validated['email'],
            invitedByUserId: (int) $this->user()?->id,
        );
    }
}
