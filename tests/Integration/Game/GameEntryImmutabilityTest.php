<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidEntryTransition;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GameEntryImmutabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createEntry(): GameEntry
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'entry-'.fake()->unique()->lexify('?????'),
            'name' => 'E',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 100,
            'prize_cents' => 500,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::SalesClosed,
        ]);
        $gameNumber = GameNumber::create([
            'game_id' => $game->id,
            'number' => 7,
            'status' => GameNumberStatus::Sold,
        ]);

        return GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $gameNumber->id,
            'user_id' => $user->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function test_create_is_allowed(): void
    {
        $entry = $this->createEntry();

        $this->assertNotNull($entry->id);
        $this->assertSame(EntryStatus::Confirmed, $entry->status);
    }

    public function test_delete_via_eloquent_throws(): void
    {
        $entry = $this->createEntry();

        $this->expectException(ImmutableModelException::class);

        $entry->delete();
    }

    public function test_modifying_user_id_throws(): void
    {
        $entry = $this->createEntry();
        $other = User::factory()->create();
        $entry->user_id = $other->id;

        $this->expectException(ImmutableModelException::class);

        $entry->save();
    }

    public function test_modifying_game_number_id_throws(): void
    {
        $entry = $this->createEntry();
        $entry->game_number_id = (string) Str::uuid7();

        $this->expectException(ImmutableModelException::class);

        $entry->save();
    }

    public function test_modifying_game_id_throws(): void
    {
        $entry = $this->createEntry();
        $entry->game_id = (string) Str::uuid7();

        $this->expectException(ImmutableModelException::class);

        $entry->save();
    }

    public function test_modifying_confirmed_at_throws(): void
    {
        $entry = $this->createEntry();
        $entry->confirmed_at = now()->subDay();

        $this->expectException(ImmutableModelException::class);

        $entry->save();
    }

    public function test_uncontrolled_status_assignment_throws(): void
    {
        $entry = $this->createEntry();
        $entry->status = EntryStatus::Winner; // bypassing transitionTo

        $this->expectException(InvalidEntryTransition::class);

        $entry->save();
    }

    public function test_status_can_change_through_transition_to(): void
    {
        $entry = $this->createEntry();

        $entry->transitionTo(EntryStatus::Winner);
        $entry->save();

        $this->assertSame(EntryStatus::Winner, $entry->refresh()->status);
    }

    public function test_invalid_transition_through_transition_to_throws(): void
    {
        $entry = $this->createEntry();
        $entry->transitionTo(EntryStatus::Winner);
        $entry->save();

        $this->expectException(InvalidEntryTransition::class);

        $entry->transitionTo(EntryStatus::Confirmed);
    }

    public function test_unique_game_number_id_blocks_double_entry(): void
    {
        $entry = $this->createEntry();
        $other = User::factory()->create();

        $this->expectException(QueryException::class);

        GameEntry::create([
            'game_id' => $entry->game_id,
            'game_number_id' => $entry->game_number_id,
            'user_id' => $other->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }
}
