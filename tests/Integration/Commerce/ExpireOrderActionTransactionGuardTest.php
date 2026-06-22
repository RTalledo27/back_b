<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Modules\Commerce\Application\Actions\ExpireOrderAction;
use App\Modules\Commerce\Application\DTOs\ExpireOrderData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class ExpireOrderActionTransactionGuardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_execute_within_transaction_fails_without_active_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $action = $this->app->make(ExpireOrderAction::class);

        $this->expectException(LogicException::class);
        $action->executeWithinTransaction(new ExpireOrderData(orderId: 'o'));
    }
}
