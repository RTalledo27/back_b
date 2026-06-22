<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberResult;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\UnsupportedDrawResultVersion;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class DrawGameNumberResultTest extends TestCase
{
    private function makeOriginal(int $currentHits = 3, bool $winnerCreated = false): DrawGameNumberResult
    {
        return new DrawGameNumberResult(
            gameId: 'game-uuid',
            drawId: 'draw-uuid',
            sequence: 7,
            drawnNumber: 4,
            gameNumberId: 'gn-uuid',
            currentHits: $currentHits,
            hitsRequired: 5,
            numberIsSold: true,
            winnerCreated: $winnerCreated,
            winnerEntryId: $winnerCreated ? 'entry-uuid' : null,
            gameStatus: $winnerCreated ? 'completed' : 'running',
            drawnAt: CarbonImmutable::parse('2026-06-22T10:00:00Z'),
            wasReplay: false,
        );
    }

    public function test_persistable_payload_excludes_was_replay(): void
    {
        $payload = $this->makeOriginal()->toPersistablePayload();
        $this->assertArrayNotHasKey('was_replay', $payload);
        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame(3, $payload['current_hits']);
    }

    public function test_roundtrip_preserves_values_except_was_replay_flag(): void
    {
        $original = $this->makeOriginal();
        $payload = $original->toPersistablePayload();

        $hydrated = DrawGameNumberResult::fromArray($payload, asReplay: true);

        $this->assertSame($original->gameId, $hydrated->gameId);
        $this->assertSame($original->drawId, $hydrated->drawId);
        $this->assertSame($original->sequence, $hydrated->sequence);
        $this->assertSame($original->drawnNumber, $hydrated->drawnNumber);
        $this->assertSame($original->gameNumberId, $hydrated->gameNumberId);
        $this->assertSame($original->currentHits, $hydrated->currentHits);
        $this->assertSame($original->hitsRequired, $hydrated->hitsRequired);
        $this->assertSame($original->numberIsSold, $hydrated->numberIsSold);
        $this->assertSame($original->winnerCreated, $hydrated->winnerCreated);
        $this->assertSame($original->winnerEntryId, $hydrated->winnerEntryId);
        $this->assertSame($original->gameStatus, $hydrated->gameStatus);
        $this->assertTrue($original->drawnAt->equalTo($hydrated->drawnAt));
        $this->assertFalse($original->wasReplay);
        $this->assertTrue($hydrated->wasReplay);
    }

    public function test_replay_preserves_historic_hits_even_if_more_draws_happened_later(): void
    {
        // Original: hits=3 (no winner).
        $original = $this->makeOriginal(currentHits: 3);
        $payload = $original->toPersistablePayload();

        // Two more draws happened of the same number elsewhere — irrelevant
        // for the historical snapshot.
        $replay = DrawGameNumberResult::fromArray($payload, asReplay: true);

        $this->assertSame(3, $replay->currentHits);
        $this->assertFalse($replay->winnerCreated);
        $this->assertTrue($replay->wasReplay);
    }

    public function test_missing_schema_version_is_rejected(): void
    {
        $this->expectException(UnsupportedDrawResultVersion::class);
        DrawGameNumberResult::fromArray(['game_id' => 'x'], asReplay: true);
    }

    public function test_unknown_schema_version_is_rejected(): void
    {
        $this->expectException(UnsupportedDrawResultVersion::class);
        DrawGameNumberResult::fromArray(['schema_version' => 999], asReplay: true);
    }

    public function test_missing_required_key_is_rejected(): void
    {
        $this->expectException(UnsupportedDrawResultVersion::class);
        DrawGameNumberResult::fromArray([
            'schema_version' => 1,
            'game_id' => 'g',
            // missing draw_id and others
        ], asReplay: true);
    }
}
