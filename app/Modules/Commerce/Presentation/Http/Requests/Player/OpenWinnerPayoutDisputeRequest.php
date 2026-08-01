<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Player;

use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class OpenWinnerPayoutDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $winner = $this->route('winner');

        return $winner instanceof GameWinner && Gate::allows('openDispute', $winner);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', 'in:funds_not_received,incorrect_amount,incorrect_destination,duplicate_payment,unrecognized_payment,other'],
            'description' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
