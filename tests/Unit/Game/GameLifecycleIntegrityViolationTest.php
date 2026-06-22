<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use PHPUnit\Framework\TestCase;

final class GameLifecycleIntegrityViolationTest extends TestCase
{
    public function test_carries_safe_context_only(): void
    {
        $e = GameLifecycleIntegrityViolation::withContext(
            'corrupted lifecycle',
            ['game_id' => 'g-uuid', 'status' => 'sales_closed', 'started_at' => '2026-06-22T08:00:00Z'],
        );

        $this->assertSame('corrupted lifecycle', $e->getMessage());
        $this->assertSame(['game_id', 'status', 'started_at'], array_keys($e->context));

        // Context must not be polluted with anything sensitive.
        foreach (['email', 'payment_id', 'document_path', 'user_id'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $e->context);
        }
    }
}
