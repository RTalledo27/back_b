<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Modules\Commerce\Application\DTOs\SubmitPaymentEvidenceData;
use PHPUnit\Framework\TestCase;

final class SubmitPaymentEvidenceDataTest extends TestCase
{
    public function test_holds_only_order_id_and_user_id(): void
    {
        $data = new SubmitPaymentEvidenceData(orderId: 'order-uuid', userId: 7);

        $this->assertSame('order-uuid', $data->orderId);
        $this->assertSame(7, $data->userId);
    }
}
