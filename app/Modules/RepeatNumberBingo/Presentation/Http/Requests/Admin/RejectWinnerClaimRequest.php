<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin;

use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class RejectWinnerClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        $claim = $this->route('claim');

        return $claim instanceof WinnerClaim
            && Gate::allows('reviewWinnerClaim', $claim);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_code' => [
                'required',
                'string',
                'in:'.implode(',', array_map('strval', (array) config('winner_claim.rejection_reason_codes', []))),
            ],
        ];
    }
}
