<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Application\Actions\DrawGameNumberAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\Services\CommittedDrawEventsDispatcher;
use App\Modules\Shared\Application\Actions\RecordOutboxEventAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Source-level guards for DrawGameNumberAction that do not require a DB
 * connection. They protect contracts that are easy to break by accident
 * during a refactor.
 */
final class DrawGameNumberActionArchitectureTest extends TestCase
{
    public function test_action_depends_on_draw_strategy_via_constructor(): void
    {
        $ref = new ReflectionClass(DrawGameNumberAction::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $params = $ctor->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame(DrawNumberStrategy::class, $params[0]->getType()?->getName());
        $this->assertSame(CommittedDrawEventsDispatcher::class, $params[1]->getType()?->getName());
        $this->assertSame(RecordOutboxEventAction::class, $params[2]->getType()?->getName());
    }

    public function test_within_transaction_method_guards_zero_level(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(DrawGameNumberAction::class))->getFileName(),
        ) ?: '';
        $this->assertStringContainsString('DB::transactionLevel() === 0', $source);
        $this->assertStringContainsString('LogicException', $source);
    }

    public function test_no_random_calls_inside_the_action(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(DrawGameNumberAction::class))->getFileName(),
        ) ?: '';
        $this->assertDoesNotMatchRegularExpression('/\brand\s*\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\bmt_rand\s*\(/', $source);
    }

    public function test_action_does_not_import_http_or_commerce(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(DrawGameNumberAction::class))->getFileName(),
        ) ?: '';
        $this->assertStringNotContainsString('Illuminate\\Http\\', $source);
        $this->assertStringNotContainsString('App\\Modules\\Commerce\\', $source);
    }
}
