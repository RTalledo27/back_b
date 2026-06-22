<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class ListGameCountersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game && Gate::allows('viewCounters', $game);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'number_from' => ['nullable', 'integer', 'min:1'],
            'number_to' => ['nullable', 'integer', 'min:1'],
            'min_hits' => ['nullable', 'integer', 'min:0'],
            'max_hits' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::enum(GameNumberStatus::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $nFrom = $this->query('number_from');
            $nTo = $this->query('number_to');
            if ($nFrom !== null && $nTo !== null && (int) $nFrom > (int) $nTo) {
                $v->errors()->add('number_to', 'number_to must be greater than or equal to number_from.');
            }

            $minHits = $this->query('min_hits');
            $maxHits = $this->query('max_hits');
            if ($minHits !== null && $maxHits !== null && (int) $minHits > (int) $maxHits) {
                $v->errors()->add('max_hits', 'max_hits must be greater than or equal to min_hits.');
            }
        });
    }
}
