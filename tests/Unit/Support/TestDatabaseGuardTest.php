<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabaseGuard;

final class TestDatabaseGuardTest extends TestCase
{
    public function test_accepts_marked_isolated_postgresql_database(): void
    {
        TestDatabaseGuard::assertSafe('testing', 'backend_rifas_app_test_worker_01');

        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeDatabaseProvider')]
    public function test_rejects_unsafe_database_names(?string $databaseName): void
    {
        $this->expectException(LogicException::class);

        TestDatabaseGuard::assertSafe('testing', $databaseName);
    }

    public function test_rejects_non_testing_environment(): void
    {
        $this->expectException(LogicException::class);

        TestDatabaseGuard::assertSafe('local', 'backend_rifas_app_test_worker_01');
    }

    /** @return array<string, array{string|null}> */
    public static function unsafeDatabaseProvider(): array
    {
        return [
            'empty' => [''],
            'missing' => [null],
            'primary' => ['backend_rifas_app'],
            'shared legacy test database' => ['backend_rifas_app_test'],
            'postgres' => ['postgres'],
            'defaultdb' => ['defaultdb'],
            'unsafe characters' => ['backend_rifas_app_test_worker-01'],
            'unmarked database' => ['some_other_test_database'],
        ];
    }
}
