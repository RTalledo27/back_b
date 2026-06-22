<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Player;

use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ReserveGameNumbersRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Game|null $game */
        $game = $this->route('game');

        return $game !== null && Gate::allows('reserve', $game);
    }

    /**
     * Distinct + uuid + min 1 ensure malformed payloads are rejected with
     * 422 before idempotency machinery is invoked. Duplicates do not get
     * silently deduplicated — they are a client error.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'game_number_ids' => ['required', 'array', 'min:1', 'max:100'],
            'game_number_ids.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }

    /**
     * @return list<string>
     */
    public function gameNumberIds(): array
    {
        /** @var array<int, string> $ids */
        $ids = $this->validated('game_number_ids');

        return array_values($ids);
    }
}
