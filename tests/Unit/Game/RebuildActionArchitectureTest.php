<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Application\Actions\RebuildGameNumberCountersAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RebuildActionArchitectureTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(
            (new ReflectionClass(RebuildGameNumberCountersAction::class))->getFileName(),
        ) ?: '';
    }

    public function test_action_does_not_modify_canonical_or_winner_tables(): void
    {
        $source = $this->source();
        foreach (['game_draws', 'draw_commands', 'game_winners', 'game_entries'] as $forbiddenTable) {
            $this->assertStringNotContainsString(
                "->table('$forbiddenTable')->insert",
                $source,
                "Rebuild action must NOT INSERT into $forbiddenTable.",
            );
            $this->assertStringNotContainsString(
                "->table('$forbiddenTable')->update",
                $source,
                "Rebuild action must NOT UPDATE $forbiddenTable.",
            );
            $this->assertStringNotContainsString(
                "->table('$forbiddenTable')->delete",
                $source,
                "Rebuild action must NOT DELETE FROM $forbiddenTable.",
            );
        }
    }

    public function test_action_does_not_depend_on_http_or_commerce(): void
    {
        $source = $this->source();
        $this->assertStringNotContainsString('Illuminate\\Http\\', $source);
        $this->assertStringNotContainsString('App\\Modules\\Commerce\\', $source);
    }

    public function test_within_transaction_method_guards_zero_level(): void
    {
        $this->assertStringContainsString('DB::transactionLevel() === 0', $this->source());
        $this->assertStringContainsString('LogicException', $this->source());
    }
}
