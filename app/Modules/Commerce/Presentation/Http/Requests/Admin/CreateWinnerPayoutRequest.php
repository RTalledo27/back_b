<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class CreateWinnerPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'destination' => ['required', 'array'],
            'destination.method' => ['required', 'string', 'in:bank_transfer,yape,plin,cash,other'],
        ];
    }

    /** @return array<string, mixed> */
    public function destination(): array
    {
        return (array) $this->input('destination');
    }
}
