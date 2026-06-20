<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin;

use App\Modules\RepeatNumberBingo\Application\DTOs\CreateGameData;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\BingoNumberRange;
use App\Modules\Shared\Domain\ValueObjects\Money;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class CreateGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Game::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'min:3', 'max:120', 'regex:/^[a-z0-9-]+$/', 'unique:games,slug'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'number_min' => ['required', 'integer', 'min:1'],
            'number_max' => ['required', 'integer', 'gt:number_min'],
            'hits_required' => ['required', 'integer', 'min:2'],
            'ticket_price_cents' => ['required', 'integer', 'min:0'],
            'prize_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'draw_interval_seconds' => ['required', 'integer', 'min:1'],
            'auto_draw_enabled' => ['required', 'boolean'],
            'sales_opens_at' => ['nullable', 'date'],
            'sales_closes_at' => ['nullable', 'date', 'after_or_equal:sales_opens_at'],
            'scheduled_start_at' => ['nullable', 'date', 'after_or_equal:sales_closes_at'],
            'settings' => ['nullable', 'array'],
        ];
    }

    public function toDto(): CreateGameData
    {
        $data = $this->validated();

        return new CreateGameData(
            slug: $data['slug'],
            name: $data['name'],
            description: $data['description'] ?? null,
            range: new BingoNumberRange(
                $data['number_min'],
                $data['number_max'],
                $data['hits_required'],
            ),
            ticketPrice: Money::of($data['ticket_price_cents'], $data['currency']),
            prize: Money::of($data['prize_cents'], $data['currency']),
            drawIntervalSeconds: $data['draw_interval_seconds'],
            autoDrawEnabled: $data['auto_draw_enabled'],
            salesOpensAt: isset($data['sales_opens_at'])
                ? new DateTimeImmutable($data['sales_opens_at'])
                : null,
            salesClosesAt: isset($data['sales_closes_at'])
                ? new DateTimeImmutable($data['sales_closes_at'])
                : null,
            scheduledStartAt: isset($data['scheduled_start_at'])
                ? new DateTimeImmutable($data['scheduled_start_at'])
                : null,
            settings: $data['settings'] ?? null,
            createdBy: $this->user()?->getKey(),
        );
    }
}
