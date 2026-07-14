<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;

final class TestDatabaseGuard
{
    private const SAFE_NAME_PATTERN = '/\Abackend_rifas_app_test_[a-z0-9_]{1,32}\z/';

    /** @var list<string> */
    private const PROTECTED_DATABASES = [
        'backend_rifas_app',
        'backend_rifas_app_test',
        'defaultdb',
        'postgres',
    ];

    public static function assertSafe(?string $environment, ?string $databaseName): void
    {
        if ($environment !== 'testing') {
            throw new LogicException('Backend tests require APP_ENV=testing.');
        }

        $databaseName = strtolower(trim((string) $databaseName));

        if ($databaseName === '') {
            throw new LogicException('Backend tests require an explicit isolated database.');
        }

        if (in_array($databaseName, self::PROTECTED_DATABASES, true)) {
            throw new LogicException("Refusing protected or shared database '{$databaseName}'.");
        }

        if (preg_match(self::SAFE_NAME_PATTERN, $databaseName) !== 1) {
            throw new LogicException("Unsafe backend test database '{$databaseName}'.");
        }
    }
}
