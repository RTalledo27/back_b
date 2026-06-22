<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidDrawCommandId;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

final class DrawCommandIdTest extends TestCase
{
    public function test_accepts_lowercase_uuid_v7(): void
    {
        $value = (string) Str::uuid7();
        $id = new DrawCommandId($value);
        $this->assertSame($value, $id->toString());
    }

    public function test_accepts_uuid_v4_too(): void
    {
        // VO does not lock to a specific version on purpose (Phase 4 jobs
        // may produce v5/uuidv8 deterministic ids).
        $id = new DrawCommandId('123e4567-e89b-12d3-a456-426614174000');
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $id->toString());
    }

    public function test_normalises_uppercase_to_lowercase(): void
    {
        $upper = strtoupper('123e4567-e89b-12d3-a456-426614174000');
        $id = new DrawCommandId($upper);
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $id->toString());
    }

    public function test_trims_surrounding_whitespace(): void
    {
        $id = new DrawCommandId('  123e4567-e89b-12d3-a456-426614174000  ');
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $id->toString());
    }

    public function test_rejects_empty_string(): void
    {
        $this->expectException(InvalidDrawCommandId::class);
        new DrawCommandId('');
    }

    public function test_rejects_arbitrary_text(): void
    {
        $this->expectException(InvalidDrawCommandId::class);
        new DrawCommandId('not-a-uuid-at-all');
    }

    public function test_rejects_uuid_without_dashes(): void
    {
        $this->expectException(InvalidDrawCommandId::class);
        new DrawCommandId('123e4567e89b12d3a456426614174000');
    }

    public function test_stringable_returns_canonical_form(): void
    {
        $id = new DrawCommandId('ABCDEF12-1234-1234-1234-1234567890AB');
        $this->assertSame('abcdef12-1234-1234-1234-1234567890ab', (string) $id);
    }

    public function test_equals_compares_normalised_value(): void
    {
        $a = new DrawCommandId('abcdef12-1234-1234-1234-1234567890ab');
        $b = new DrawCommandId('ABCDEF12-1234-1234-1234-1234567890AB');
        $this->assertTrue($a->equals($b));
    }
}
