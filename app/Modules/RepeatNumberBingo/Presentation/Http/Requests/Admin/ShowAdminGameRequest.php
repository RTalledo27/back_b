<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin;

use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ShowAdminGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game && Gate::allows('view', $game);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
