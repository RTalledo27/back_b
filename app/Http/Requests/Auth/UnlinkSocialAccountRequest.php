<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class UnlinkSocialAccountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();
        $hasPassword = $user !== null && $user->password !== null;

        return [
            // Required for users with a local password (reautenticación defence).
            // Social-only users are authenticated via their Sanctum token.
            'current_password' => [
                $hasPassword ? 'required' : 'nullable',
                'string',
            ],
        ];
    }
}
