<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Domain\Services\EngineTickCommandIdGenerator;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EngineTickCommandIdGeneratorTest extends TestCase
{
    private const NAMESPACE = 'a1b2c3d4-e5f6-4789-abcd-ef0123456789';

    private const ALT_NAMESPACE = 'ffffffff-ffff-4fff-bfff-ffffffffffff';

    private EngineTickCommandIdGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new EngineTickCommandIdGenerator(self::NAMESPACE);
    }

    // -------------------------------------------------------------------------
    // Constructor validation
    // -------------------------------------------------------------------------

    public function test_rejects_empty_namespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EngineTickCommandIdGenerator('');
    }

    public function test_rejects_arbitrary_string_namespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EngineTickCommandIdGenerator('not-a-uuid');
    }

    public function test_rejects_uuid_without_dashes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EngineTickCommandIdGenerator('a1b2c3d4e5f64789abcdef0123456789');
    }

    // -------------------------------------------------------------------------
    // Determinism
    // -------------------------------------------------------------------------

    public function test_returns_draw_command_id(): void
    {
        $id = $this->generator->generate(
            '550e8400-e29b-41d4-a716-446655440000',
            CarbonImmutable::parse('2026-06-22 10:00:00'),
        );

        $this->assertInstanceOf(DrawCommandId::class, $id);
    }

    public function test_same_inputs_produce_same_id(): void
    {
        $gameId = '550e8400-e29b-41d4-a716-446655440000';
        $scheduledAt = CarbonImmutable::parse('2026-06-22 10:00:00');

        $a = $this->generator->generate($gameId, $scheduledAt);
        $b = $this->generator->generate($gameId, $scheduledAt);

        $this->assertTrue($a->equals($b));
    }

    public function test_different_game_ids_produce_different_ids(): void
    {
        $scheduledAt = CarbonImmutable::parse('2026-06-22 10:00:00');

        $a = $this->generator->generate('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $scheduledAt);
        $b = $this->generator->generate('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', $scheduledAt);

        $this->assertFalse($a->equals($b));
    }

    public function test_different_scheduled_at_produce_different_ids(): void
    {
        $gameId = '550e8400-e29b-41d4-a716-446655440000';

        $a = $this->generator->generate($gameId, CarbonImmutable::parse('2026-06-22 10:00:00'));
        $b = $this->generator->generate($gameId, CarbonImmutable::parse('2026-06-22 10:00:30'));

        $this->assertFalse($a->equals($b));
    }

    public function test_different_namespace_produces_different_id(): void
    {
        $gameId = '550e8400-e29b-41d4-a716-446655440000';
        $scheduledAt = CarbonImmutable::parse('2026-06-22 10:00:00');

        $a = $this->generator->generate($gameId, $scheduledAt);
        $b = (new EngineTickCommandIdGenerator(self::ALT_NAMESPACE))->generate($gameId, $scheduledAt);

        $this->assertFalse($a->equals($b));
    }

    public function test_same_namespace_injected_directly_matches_config_derived(): void
    {
        $gameId = '550e8400-e29b-41d4-a716-446655440000';
        $scheduledAt = CarbonImmutable::parse('2026-06-22 10:00:00');

        $a = $this->generator->generate($gameId, $scheduledAt);
        $b = (new EngineTickCommandIdGenerator(self::NAMESPACE))->generate($gameId, $scheduledAt);

        $this->assertTrue($a->equals($b));
    }

    public function test_output_is_valid_uuid(): void
    {
        $id = $this->generator->generate(
            '550e8400-e29b-41d4-a716-446655440000',
            CarbonImmutable::parse('2026-06-22 10:00:00'),
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $id->toString(),
        );
    }
}
