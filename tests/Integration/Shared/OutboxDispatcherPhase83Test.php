<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use App\Models\OutboxEvent;
use App\Modules\Shared\Infrastructure\Outbox\OutboxEventDispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Integration tests verifying that OutboxEventDispatcher handles all
 * Phase 8.3 event types and rejects unknown types (Phase 8.3).
 */
final class OutboxDispatcherPhase83Test extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALLOWED_TYPES = [
        'payment_approved',
        'payment_rejected',
        'order_refunded',
        'winner_payout_registered',
        'game_winner_declared',
    ];

    private function insertEvent(string $eventType): OutboxEvent
    {
        $id = (string) Str::uuid7();

        DB::table('outbox_events')->insert([
            'id' => $id,
            'event_type' => $eventType,
            'aggregate_type' => 'test',
            'aggregate_id' => null,
            'deduplication_key' => null,
            'payload' => json_encode(['schema_version' => 1]),
            'available_at' => now()->subSecond(),
            'attempts' => 0,
            'max_attempts' => 5,
            'created_at' => now(),
        ]);

        return OutboxEvent::findOrFail($id);
    }

    private function dispatcher(): OutboxEventDispatcher
    {
        return $this->app->make(OutboxEventDispatcher::class);
    }

    #[DataProvider('allowedEventTypesProvider')]
    public function test_dispatcher_accepts_allowed_event_type(string $eventType): void
    {
        $event = $this->insertEvent($eventType);

        // No exception means the dispatcher handled the type correctly.
        $this->dispatcher()->dispatch($event);

        $this->assertTrue(true);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedEventTypesProvider(): array
    {
        return array_combine(
            self::ALLOWED_TYPES,
            array_map(fn (string $t) => [$t], self::ALLOWED_TYPES),
        );
    }

    public function test_dispatcher_rejects_unknown_event_type_with_runtime_exception(): void
    {
        $event = $this->insertEvent('payment_approved'); // valid insert
        $event->event_type = 'unknown_event_does_not_exist';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/unknown event_type 'unknown_event_does_not_exist'/");

        $this->dispatcher()->dispatch($event);
    }

    public function test_dispatcher_rejects_future_unknown_type(): void
    {
        $event = $this->insertEvent('payment_approved');
        $event->event_type = 'game_cancelled'; // future event not yet in Phase 8.3

        $this->expectException(RuntimeException::class);

        $this->dispatcher()->dispatch($event);
    }

    public function test_dispatcher_covers_exactly_five_event_types(): void
    {
        $this->assertCount(5, self::ALLOWED_TYPES);

        $dispatcherFile = file_get_contents(
            __DIR__.'/../../../app/Modules/Shared/Infrastructure/Outbox/OutboxEventDispatcher.php'
        );

        $this->assertNotFalse($dispatcherFile);

        foreach (self::ALLOWED_TYPES as $type) {
            $this->assertStringContainsString(
                "'{$type}'",
                $dispatcherFile,
                "Dispatcher must handle event type '{$type}'."
            );
        }

        // Guard that exactly 5 private handler methods exist (one per event type).
        $handlerCount = preg_match_all('/private function handle\w+\(OutboxEvent/', $dispatcherFile, $matches);
        $this->assertSame(5, $handlerCount, 'Dispatcher must have exactly 5 handler methods (Phase 8.3).');
    }
}
