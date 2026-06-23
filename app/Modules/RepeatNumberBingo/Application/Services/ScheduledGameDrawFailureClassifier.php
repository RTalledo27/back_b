<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Services;

use App\Modules\RepeatNumberBingo\Application\DTOs\ScheduledGameDrawFailureType;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\DrawnNumberOutOfRange;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameAlreadyCompleted;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameEngineAutomationActive;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameEngineAutomationInactive;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameParticipationIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidEntryTransition;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameEngineConfiguration;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameNumberTransition;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameTransition;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\UnsupportedDrawResultVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

final class ScheduledGameDrawFailureClassifier
{
    public function classify(Throwable $exception): ScheduledGameDrawFailureType
    {
        if ($this->isExpected($exception)) {
            return ScheduledGameDrawFailureType::Expected;
        }

        if ($this->isIntegrityFailure($exception)) {
            return ScheduledGameDrawFailureType::Integrity;
        }

        return ScheduledGameDrawFailureType::Transient;
    }

    public function code(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            $sqlState = $this->sqlState($exception);

            if ($sqlState !== null) {
                return 'sqlstate_'.Str::lower($sqlState);
            }
        }

        return Str::of(class_basename($exception))->snake()->toString();
    }

    public function sqlState(Throwable $exception): ?string
    {
        if (! $exception instanceof QueryException) {
            return null;
        }

        $sqlState = $exception->errorInfo[0] ?? null;

        return is_string($sqlState) && $sqlState !== '' ? $sqlState : null;
    }

    private function isExpected(Throwable $exception): bool
    {
        return $exception instanceof ModelNotFoundException
            || $exception instanceof GameAlreadyCompleted
            || $exception instanceof GameEngineAutomationActive
            || $exception instanceof GameEngineAutomationInactive
            || $exception instanceof InvalidGameTransition;
    }

    private function isIntegrityFailure(Throwable $exception): bool
    {
        if (
            $exception instanceof GameLifecycleIntegrityViolation
            || $exception instanceof GameParticipationIntegrityViolation
            || $exception instanceof DrawnNumberOutOfRange
            || $exception instanceof UnsupportedDrawResultVersion
            || $exception instanceof InvalidGameEngineConfiguration
            || $exception instanceof InvalidEntryTransition
            || $exception instanceof InvalidGameNumberTransition
            || $exception instanceof UniqueConstraintViolationException
        ) {
            return true;
        }

        if (! $exception instanceof QueryException) {
            return false;
        }

        return str_starts_with($this->sqlState($exception) ?? '', '23');
    }
}
