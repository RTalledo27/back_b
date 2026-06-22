<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Modules\Commerce\Application\Actions\ApprovePaymentAction;
use App\Modules\Commerce\Application\Actions\RejectPaymentAction;
use App\Modules\Commerce\Application\DTOs\ApprovePaymentData;
use App\Modules\Commerce\Application\DTOs\RejectPaymentData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class ApproveRejectActionTransactionGuardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_approve_execute_within_transaction_fails_without_active_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $action = $this->app->make(ApprovePaymentAction::class);

        $this->expectException(LogicException::class);
        $action->executeWithinTransaction(new ApprovePaymentData(paymentId: 'p', reviewerUserId: 1));
    }

    public function test_reject_execute_within_transaction_fails_without_active_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $action = $this->app->make(RejectPaymentAction::class);

        $this->expectException(LogicException::class);
        $action->executeWithinTransaction(new RejectPaymentData(
            paymentId: 'p', reviewerUserId: 1, reason: 'because',
        ));
    }
}
