<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

use App\Modules\RepeatNumberBingo\Domain\Exceptions\UnsupportedDrawResultVersion;
use Carbon\CarbonImmutable;

/**
 * Result DTO of a single draw execution. Its toPersistablePayload() is
 * what gets stored in draw_commands.result_payload. The wasReplay flag
 * describes the CURRENT invocation, not the historical record — it is
 * therefore excluded from the persisted snapshot and set explicitly by
 * fromArray() at hydration time.
 */
final readonly class DrawGameNumberResult
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public string $gameId,
        public string $drawId,
        public int $sequence,
        public int $drawnNumber,
        public string $gameNumberId,
        public int $currentHits,
        public int $hitsRequired,
        public bool $numberIsSold,
        public bool $winnerCreated,
        public ?string $winnerEntryId,
        public string $gameStatus,
        public CarbonImmutable $drawnAt,
        public bool $wasReplay,
    ) {}

    /**
     * Build the JSON-safe shape that will be written to
     * draw_commands.result_payload. `wasReplay` is intentionally omitted
     * because it describes the calling invocation, not the historical
     * fact.
     *
     * @return array<string, mixed>
     */
    public function toPersistablePayload(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'game_id' => $this->gameId,
            'draw_id' => $this->drawId,
            'sequence' => $this->sequence,
            'drawn_number' => $this->drawnNumber,
            'game_number_id' => $this->gameNumberId,
            'current_hits' => $this->currentHits,
            'hits_required' => $this->hitsRequired,
            'number_is_sold' => $this->numberIsSold,
            'winner_created' => $this->winnerCreated,
            'winner_entry_id' => $this->winnerEntryId,
            'game_status' => $this->gameStatus,
            'drawn_at' => $this->drawnAt->toIso8601String(),
        ];
    }

    /**
     * Hydrate from a persisted payload. `$asReplay` reflects the current
     * invocation and overrides any value that might be present in the
     * payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload, bool $asReplay): self
    {
        if (! array_key_exists('schema_version', $payload)) {
            throw UnsupportedDrawResultVersion::missing();
        }

        $version = $payload['schema_version'];
        if ($version !== self::SCHEMA_VERSION) {
            throw UnsupportedDrawResultVersion::got($version);
        }

        $required = [
            'game_id', 'draw_id', 'sequence', 'drawn_number', 'game_number_id',
            'current_hits', 'hits_required', 'number_is_sold', 'winner_created',
            'game_status', 'drawn_at',
        ];
        foreach ($required as $key) {
            if (! array_key_exists($key, $payload)) {
                throw UnsupportedDrawResultVersion::got(
                    sprintf('v%d missing key "%s"', self::SCHEMA_VERSION, $key)
                );
            }
        }

        return new self(
            gameId: (string) $payload['game_id'],
            drawId: (string) $payload['draw_id'],
            sequence: (int) $payload['sequence'],
            drawnNumber: (int) $payload['drawn_number'],
            gameNumberId: (string) $payload['game_number_id'],
            currentHits: (int) $payload['current_hits'],
            hitsRequired: (int) $payload['hits_required'],
            numberIsSold: (bool) $payload['number_is_sold'],
            winnerCreated: (bool) $payload['winner_created'],
            winnerEntryId: isset($payload['winner_entry_id']) ? (string) $payload['winner_entry_id'] : null,
            gameStatus: (string) $payload['game_status'],
            drawnAt: CarbonImmutable::parse((string) $payload['drawn_at']),
            wasReplay: $asReplay,
        );
    }
}
