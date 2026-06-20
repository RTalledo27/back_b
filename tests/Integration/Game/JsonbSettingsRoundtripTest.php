<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class JsonbSettingsRoundtripTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_nested_jsonb_settings_survive_round_trip(): void
    {
        $settings = [
            'channels' => ['whatsapp', 'sms'],
            'limits' => ['max_orders_per_user' => 3, 'reservation_minutes' => 10],
            'flags' => ['public_audit' => true],
        ];

        $game = Game::create([
            'slug' => 'jsonb-game',
            'name' => 'JSONB',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 100,
            'prize_cents' => 500,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Draft,
            'settings' => $settings,
        ]);

        $loaded = Game::query()->whereKey($game->id)->firstOrFail();

        // PostgreSQL JSONB does not preserve key order in associative objects,
        // so we verify shape and values explicitly instead of full array equality.
        $this->assertSame(['whatsapp', 'sms'], $loaded->settings['channels']);
        $this->assertSame(3, $loaded->settings['limits']['max_orders_per_user']);
        $this->assertSame(10, $loaded->settings['limits']['reservation_minutes']);
        $this->assertTrue($loaded->settings['flags']['public_audit']);
        $this->assertEqualsCanonicalizing(
            array_keys($settings),
            array_keys($loaded->settings),
        );
    }
}
