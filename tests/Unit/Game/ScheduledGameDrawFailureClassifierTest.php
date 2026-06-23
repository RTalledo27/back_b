<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Application\DTOs\ScheduledGameDrawFailureType;
use App\Modules\RepeatNumberBingo\Application\Services\ScheduledGameDrawFailureClassifier;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScheduledGameDrawFailureClassifierTest extends TestCase
{
    private ScheduledGameDrawFailureClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ScheduledGameDrawFailureClassifier;
    }

    public function test_model_not_found_is_expected(): void
    {
        $this->assertSame(
            ScheduledGameDrawFailureType::Expected,
            $this->classifier->classify(new ModelNotFoundException),
        );
    }

    public function test_domain_integrity_violation_is_integrity(): void
    {
        $exception = GameLifecycleIntegrityViolation::withContext('corrupt', ['game_id' => 'g']);

        $this->assertSame(
            ScheduledGameDrawFailureType::Integrity,
            $this->classifier->classify($exception),
        );
    }

    public function test_constraint_sqlstate_is_integrity(): void
    {
        $exception = $this->queryException('23505');

        $this->assertSame(
            ScheduledGameDrawFailureType::Integrity,
            $this->classifier->classify($exception),
        );
        $this->assertSame('23505', $this->classifier->sqlState($exception));
        $this->assertSame('sqlstate_23505', $this->classifier->code($exception));
    }

    public function test_deadlock_and_unknown_runtime_failures_are_transient(): void
    {
        $this->assertSame(
            ScheduledGameDrawFailureType::Transient,
            $this->classifier->classify($this->queryException('40P01')),
        );
        $this->assertSame(
            ScheduledGameDrawFailureType::Transient,
            $this->classifier->classify(new RuntimeException('temporary')),
        );
    }

    private function queryException(string $sqlState): QueryException
    {
        $previous = new PDOException('database failure');
        $previous->errorInfo = [$sqlState];

        return new QueryException('pgsql', 'select 1', [], $previous);
    }
}
