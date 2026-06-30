<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Actions\ProcessWinnerPayoutAction;
use App\Modules\Commerce\Application\DTOs\ProcessWinnerPayoutData;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Integration tests for the outbox WinnerPayoutRegistered event (Phase 8.3).
 */
final class OutboxWinnerPayoutIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const KEY_A = 'payout-outbox-key-aaaaaaaaaaaa';

    private const KEY_B = 'payout-outbox-key-bbbbbbbbbbbb';

    /**
     * @return array{Game, GameWinner, int} [game, winner, actorUserId]
     */
    private function setupCompletedGameWithWinner(): array
    {
        $buyer = User::factory()->create();
        $actor = User::factory()->admin()->create();

        $game = Game::create([
            'slug' => 'owp-'.fake()->unique()->lexify('?????'),
            'name' => 'OutboxPayoutTest',
            'number_min' => 1, 'number_max' => 10, 'hits_required' => 3,
            'ticket_price_cents' => 1000, 'prize_cents' => 50000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false,
            'status' => GameStatus::Completed,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinutes(30),
        ]);

        $gn = GameNumber::create([
            'game_id' => $game->id, 'number' => 1,
            'status' => GameNumberStatus::Sold,
        ]);

        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'user_id' => $buyer->id,
            'status' => EntryStatus::Winner,
            'confirmed_at' => now()->subMinutes(25),
        ]);

        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'sequence' => 1,
            'drawn_number' => 1,
            'drawn_at' => now()->subMinutes(20),
            'strategy' => 'deterministic',
            'created_at' => now()->subMinutes(20),
        ]);

        $winner = GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $gn->id,
            'user_id' => $buyer->id,
            'winning_hits' => 3,
            'won_at' => now()->subMinutes(15),
        ]);

        return [$game, $winner, $actor->id];
    }

    private function action(): ProcessWinnerPayoutAction
    {
        return $this->app->make(ProcessWinnerPayoutAction::class);
    }

    private function makeData(string $gameId, int $actorUserId, string $key = self::KEY_A): ProcessWinnerPayoutData
    {
        return new ProcessWinnerPayoutData(
            gameId: $gameId,
            actorUserId: $actorUserId,
            externalReference: 'OP-REF-'.Str::upper(Str::random(6)),
            notes: null,
            idempotencyKeyHash: hash('sha256', $key),
            documentDisk: 'local',
            documentPath: 'payouts/test-doc.pdf',
            documentOriginalFilename: 'comprobante.pdf',
            documentMimeType: 'application/pdf',
            documentSizeBytes: 12345,
            documentSha256: hash('sha256', 'fake-document-content'),
        );
    }

    public function test_payout_inserts_outbox_event_inside_transaction(): void
    {
        [$game, $winner, $actorId] = $this->setupCompletedGameWithWinner();

        DB::transaction(fn () => $this->action()->executeWithinTransaction(
            $this->makeData((string) $game->id, $actorId)
        ));

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'winner_payout_registered',
            'aggregate_type' => 'game_winner',
            'aggregate_id' => (string) $winner->id,
            'deduplication_key' => 'winner_payout_registered:'.(string) $winner->id,
        ]);
    }

    public function test_outbox_payload_contains_required_fields(): void
    {
        [$game, $winner, $actorId] = $this->setupCompletedGameWithWinner();

        DB::transaction(fn () => $this->action()->executeWithinTransaction(
            $this->makeData((string) $game->id, $actorId)
        ));

        $row = DB::table('outbox_events')->where('event_type', 'winner_payout_registered')->first();
        $this->assertNotNull($row);
        $payload = json_decode($row->payload, true);

        $this->assertSame(1, $payload['schema_version']);
        $this->assertArrayHasKey('winner_payout_id', $payload);
        $this->assertSame((string) $winner->id, $payload['game_winner_id']);
        $this->assertSame((string) $game->id, $payload['game_id']);
        $this->assertArrayHasKey('winner_user_id', $payload);
        $this->assertArrayHasKey('occurred_at', $payload);
    }

    public function test_outbox_payload_does_not_contain_sensitive_fields(): void
    {
        [$game, , $actorId] = $this->setupCompletedGameWithWinner();

        DB::transaction(fn () => $this->action()->executeWithinTransaction(
            $this->makeData((string) $game->id, $actorId)
        ));

        $row = DB::table('outbox_events')->where('event_type', 'winner_payout_registered')->first();
        $this->assertNotNull($row);
        $payload = json_decode($row->payload, true);

        $forbidden = [
            'path', 'disk', 'sha256', 'original_filename', 'external_reference',
            'idempotency_key_hash', 'email', 'name', 'phone',
        ];

        foreach ($forbidden as $field) {
            $this->assertArrayNotHasKey($field, $payload, "Outbox payload must not contain '{$field}'.");
        }
    }

    public function test_idempotent_replay_does_not_duplicate_outbox(): void
    {
        [$game, , $actorId] = $this->setupCompletedGameWithWinner();
        $data = $this->makeData((string) $game->id, $actorId, self::KEY_A);

        // First call: new payout, inserts outbox row
        $r1 = DB::transaction(fn () => $this->action()->executeWithinTransaction($data));
        $this->assertFalse($r1->wasAlreadyProcessed);
        $this->assertDatabaseCount('outbox_events', 1);

        // Second call: same key = replay, returns existing, no outbox
        $r2 = DB::transaction(fn () => $this->action()->executeWithinTransaction($data));
        $this->assertTrue($r2->wasAlreadyProcessed);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_different_key_after_existing_payout_does_not_duplicate_outbox(): void
    {
        [$game, , $actorId] = $this->setupCompletedGameWithWinner();

        // First call KEY_A
        DB::transaction(fn () => $this->action()->executeWithinTransaction(
            $this->makeData((string) $game->id, $actorId, self::KEY_A)
        ));
        $this->assertDatabaseCount('outbox_events', 1);

        // Second call KEY_B (different caller, same game_winner) returns existing
        DB::transaction(fn () => $this->action()->executeWithinTransaction(
            $this->makeData((string) $game->id, $actorId, self::KEY_B)
        ));
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_rollback_removes_outbox_row(): void
    {
        [$game, , $actorId] = $this->setupCompletedGameWithWinner();

        try {
            DB::transaction(function () use ($game, $actorId): void {
                $this->action()->executeWithinTransaction($this->makeData((string) $game->id, $actorId));
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseCount('outbox_events', 0);
    }
}
