<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin;

use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ListGameDrawsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game && Gate::allows('viewDraws', $game);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'number' => ['nullable', 'integer', 'min:1'],
            'sequence_from' => ['nullable', 'integer', 'min:1'],
            'sequence_to' => ['nullable', 'integer', 'min:1'],
            'drawn_from' => ['nullable', 'date'],
            'drawn_to' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $from = $this->query('sequence_from');
            $to = $this->query('sequence_to');
            if ($from !== null && $to !== null && (int) $from > (int) $to) {
                $v->errors()->add('sequence_to', 'sequence_to must be greater than or equal to sequence_from.');
            }

            $dFrom = $this->query('drawn_from');
            $dTo = $this->query('drawn_to');
            if (is_string($dFrom) && is_string($dTo) && $dFrom !== '' && $dTo !== '' && $dFrom > $dTo) {
                $v->errors()->add('drawn_to', 'drawn_to must be greater than or equal to drawn_from.');
            }
        });
    }
}
