<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Domain\Enums\ActorType;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\GameActionActor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GameActionActorTest extends TestCase
{
    public function test_admin_factory_sets_type_and_user_id(): void
    {
        $actor = GameActionActor::admin(42);

        $this->assertSame(ActorType::Admin, $actor->type);
        $this->assertSame(42, $actor->userId);
        $this->assertTrue($actor->isAdmin());
        $this->assertFalse($actor->isSystem());
    }

    public function test_system_factory_sets_type_and_null_user_id(): void
    {
        $actor = GameActionActor::system();

        $this->assertSame(ActorType::System, $actor->type);
        $this->assertNull($actor->userId);
        $this->assertTrue($actor->isSystem());
        $this->assertFalse($actor->isAdmin());
    }

    public function test_constructor_accepts_null_user_id_for_system(): void
    {
        $actor = new GameActionActor(ActorType::System, null);

        $this->assertNull($actor->userId);
    }

    public function test_admin_type_value_is_admin_string(): void
    {
        $this->assertSame('admin', ActorType::Admin->value);
    }

    public function test_system_type_value_is_system_string(): void
    {
        $this->assertSame('system', ActorType::System->value);
    }

    public function test_admin_factory_rejects_zero_user_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GameActionActor::admin(0);
    }

    public function test_admin_factory_rejects_negative_user_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GameActionActor::admin(-1);
    }
}
