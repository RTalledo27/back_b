<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Modules\Commerce\Application\Support\WinnerPayoutDestinationFactory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WinnerPayoutDestinationFactoryTest extends TestCase
{
    #[Test]
    public function it_accepts_each_supported_destination_method(): void
    {
        $factory = new WinnerPayoutDestinationFactory();

        $destinations = [
            ['method' => 'bank_transfer', 'account' => '123456789', 'bank_name' => 'Banco'],
            ['method' => 'yape', 'phone' => '999111222'],
            ['method' => 'plin', 'identifier' => '999111222'],
            ['method' => 'cash', 'reference' => 'CASH-001', 'location' => 'Lima'],
            ['method' => 'other', 'category' => 'manual', 'reference' => 'REF-001'],
        ];

        foreach ($destinations as $destination) {
            $result = $factory->make($destination);

            self::assertSame($destination['method'], $result->method);
            self::assertNotSame('', $result->masked);
            self::assertArrayNotHasKey('method', $result->payload);
        }
    }

    #[Test]
    public function it_rejects_unexpected_and_sensitive_destination_fields(): void
    {
        $factory = new WinnerPayoutDestinationFactory();

        $this->expectException(ValidationException::class);
        $factory->make([
            'method' => 'yape',
            'phone' => '999111222',
            'token' => 'secret-value',
        ]);
    }

    #[Test]
    public function it_masks_other_destinations_without_exposing_the_primary_value(): void
    {
        $result = (new WinnerPayoutDestinationFactory())->make([
            'method' => 'other',
            'category' => 'manual-transfer',
        ]);

        self::assertSame('other:****sfer', $result->masked);
    }
}
