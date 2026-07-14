<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        TestDatabaseGuard::assertSafe(
            getenv('APP_ENV') ?: null,
            getenv('DB_DATABASE') ?: null,
        );

        parent::setUp();

        $connection = (string) config('database.default');
        TestDatabaseGuard::assertSafe(
            (string) app()->environment(),
            (string) config("database.connections.{$connection}.database"),
        );
    }
}
