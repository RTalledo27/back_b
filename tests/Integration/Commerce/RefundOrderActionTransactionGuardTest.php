<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Modules\Commerce\Application\Actions\RefundOrderAction;
use App\Modules\Commerce\Application\DTOs\RefundOrderData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class RefundOrderActionTransactionGuardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_execute_within_transaction_fails_without_active_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $action = $this->app->make(RefundOrderAction::class);

        $data = new RefundOrderData(
            orderId: '00000000-0000-7000-8000-000000000001',
            actorUserId: 1,
            reason: 'Test reason for transaction guard verification.',
            idempotencyKeyHash: str_repeat('a', 64),
            requestFingerprint: str_repeat('b', 64),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('executeWithinTransaction requires an active database transaction');

        $action->executeWithinTransaction($data, '00000000-0000-7000-8000-000000000002');
    }
}
