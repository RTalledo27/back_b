<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class GameSlugUniqueConstraintTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_postgres_rejects_duplicate_slug_at_database_level(): void
    {
        Game::create($this->payload('same-slug'));

        $this->expectException(QueryException::class);

        Game::create($this->payload('same-slug'));
    }

    /** @return array<string, mixed> */
    private function payload(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => 'X',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 100,
            'prize_cents' => 500,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Draft,
        ];
    }
}
