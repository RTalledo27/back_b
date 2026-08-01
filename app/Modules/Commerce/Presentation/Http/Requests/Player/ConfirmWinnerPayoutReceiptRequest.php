<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Player;

use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ConfirmWinnerPayoutReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $winner = $this->route('winner');

        return $winner instanceof GameWinner && Gate::allows('confirmReceipt', $winner);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['accepted' => ['accepted']];
    }
}
