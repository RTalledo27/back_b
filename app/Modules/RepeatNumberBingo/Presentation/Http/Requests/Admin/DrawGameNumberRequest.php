<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin;

use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class DrawGameNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game && Gate::allows('draw', $game);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $raw = $this->header('X-Draw-Command-Id');
            if ($raw === null || $raw === '') {
                $v->errors()->add('X-Draw-Command-Id', 'Header X-Draw-Command-Id is required.');

                return;
            }
            if (! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', trim((string) $raw))) {
                $v->errors()->add('X-Draw-Command-Id', 'Header X-Draw-Command-Id must be a UUID.');
            }
        });
    }

    public function drawCommandId(): DrawCommandId
    {
        return new DrawCommandId((string) $this->header('X-Draw-Command-Id'));
    }
}
