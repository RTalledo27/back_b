<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Modules\Commerce\Application\DTOs\ReserveGameNumbersData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReserveGameNumbersDataTest extends TestCase
{
    public function test_rejects_empty_id_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReserveGameNumbersData(
            gameId: '01900000-0000-7000-8000-000000000000',
            userId: 1,
            gameNumberIds: [],
        );
    }

    public function test_rejects_empty_string_inside_id_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReserveGameNumbersData(
            gameId: '01900000-0000-7000-8000-000000000000',
            userId: 1,
            gameNumberIds: ['valid-id', ''],
        );
    }

    public function test_preserves_id_list_as_given(): void
    {
        $data = new ReserveGameNumbersData(
            gameId: 'g',
            userId: 1,
            gameNumberIds: ['B', 'A', 'C'],
        );

        // The DTO is a passive value object; sorting/dedup are caller concerns.
        $this->assertSame(['B', 'A', 'C'], $data->gameNumberIds);
        $this->assertSame(1, $data->userId);
        $this->assertSame('g', $data->gameId);
    }
}
