<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Player;

use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class SubmitWinnerClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        $winner = $this->route('winner');

        return $winner instanceof GameWinner
            && Gate::allows('submitWinnerClaim', $winner);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxSize = (int) config('winner_claim.identity.max_size_kb', 5120);

        return [
            'legal_name' => ['required', 'string', 'max:160'],
            'document_type' => ['required', 'string', 'in:'.implode(',', array_map('strval', (array) config('winner_claim.identity.document_types', [])))],
            'document_number' => ['required', 'string', 'max:40'],
            'accepted_prize_terms' => ['accepted'],
            'consented_identity_processing' => ['accepted'],
            'identity_front' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', "max:{$maxSize}"],
            'identity_back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', "max:{$maxSize}"],
            'identity_additional' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', "max:{$maxSize}"],
        ];
    }
}
