<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class ListAdminGamesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Game::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(array_column(GameStatus::cases(), 'value'))],
            'published' => ['nullable', 'boolean'],
            'auto_draw_enabled' => ['nullable', 'boolean'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $from = $this->query('created_from');
            $to = $this->query('created_to');
            if (is_string($from) && is_string($to) && $from !== '' && $to !== '' && $from > $to) {
                $v->errors()->add('created_to', 'created_to must be greater than or equal to created_from.');
            }
        });
    }
}
