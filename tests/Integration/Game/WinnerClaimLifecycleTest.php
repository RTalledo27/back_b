<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\CreateWinnerClaimAction;
use App\Modules\RepeatNumberBingo\Application\Actions\ExpireWinnerClaimAction;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaimEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WinnerClaimLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_new_winner_creates_pending_claim_with_window_and_event(): void
    {
        $winner = $this->winner();

        DB::transaction(fn (): WinnerClaim => $this->app
            ->make(CreateWinnerClaimAction::class)
            ->executeWithinTransaction($winner->id));

        $claim = WinnerClaim::query()->where('game_winner_id', $winner->id)->firstOrFail();

        $this->assertSame(WinnerClaimStatus::PendingClaim, $claim->status);
        $this->assertFalse($claim->is_legacy);
        $this->assertNotNull($claim->claim_window_started_at);
        $this->assertNotNull($claim->expires_at);
        $this->assertTrue($claim->expires_at->greaterThan($claim->claim_window_started_at));
        $this->assertSame(1, WinnerClaimEvent::query()
            ->where('winner_claim_id', $claim->id)
            ->where('event_type', WinnerClaimEventType::ClaimCreated)
            ->count());
    }

    public function test_legacy_claim_has_no_invented_window_and_is_not_expired(): void
    {
        $winner = $this->winner();
        $claim = WinnerClaim::create([
            'game_winner_id' => $winner->id,
            'winner_user_id' => $winner->user_id,
            'claim_reference' => 'LEGACY-'.fake()->unique()->lexify('????????????????'),
            'status' => WinnerClaimStatus::PendingClaim,
            'is_legacy' => true,
        ]);

        $this->assertNull($claim->claim_window_started_at);
        $this->assertNull($claim->expires_at);
        $this->assertFalse($this->app->make(ExpireWinnerClaimAction::class)->execute($claim->id));
        $this->assertSame(WinnerClaimStatus::PendingClaim, $claim->refresh()->status);
    }

    public function test_expiration_is_idempotent_and_only_pending_claims_expire(): void
    {
        $winner = $this->winner();
        $claim = WinnerClaim::create([
            'game_winner_id' => $winner->id,
            'winner_user_id' => $winner->user_id,
            'claim_reference' => 'EXPIRING-'.fake()->unique()->lexify('????????????????'),
            'status' => WinnerClaimStatus::PendingClaim,
            'claim_window_started_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
            'is_legacy' => false,
        ]);

        $action = $this->app->make(ExpireWinnerClaimAction::class);
        $this->assertTrue($action->execute($claim->id));
        $this->assertFalse($action->execute($claim->id));
        $this->assertSame(WinnerClaimStatus::Expired, $claim->refresh()->status);
        $this->assertSame(1, WinnerClaimEvent::query()
            ->where('winner_claim_id', $claim->id)
            ->where('event_type', WinnerClaimEventType::ClaimExpired)
            ->count());
    }

    public function test_terminal_claim_statuses_cannot_transition_back_to_identity_pending(): void
    {
        foreach ([WinnerClaimStatus::Verified, WinnerClaimStatus::Rejected, WinnerClaimStatus::Expired] as $status) {
            $this->assertFalse($status->canTransitionTo(WinnerClaimStatus::IdentityPending));
        }
    }

    private function winner(): GameWinner
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'winner-claim-lifecycle-'.fake()->unique()->lexify('????'),
            'name' => 'Winner claim lifecycle',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 2,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false,
            'status' => GameStatus::Completed,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute(),
        ]);
        $number = GameNumber::create(['game_id' => $game->id, 'number' => 3, 'status' => GameNumberStatus::Sold]);
        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'user_id' => $user->id,
            'status' => EntryStatus::Winner,
            'confirmed_at' => now()->subMinutes(2),
        ]);
        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'sequence' => 1,
            'drawn_number' => 3,
            'drawn_at' => now()->subMinute(),
            'strategy' => 'test',
        ]);

        return GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $number->id,
            'user_id' => $user->id,
            'winning_hits' => 2,
            'won_at' => now()->subMinute(),
        ]);
    }
}
