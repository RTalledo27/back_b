<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Modules\Commerce\Application\Actions\ReserveGameNumbersAction;
use App\Modules\Commerce\Application\DTOs\ReserveGameNumbersData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class ReserveGameNumbersActionTransactionGuardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_execute_within_transaction_fails_without_active_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $action = $this->app->make(ReserveGameNumbersAction::class);

        $this->expectException(LogicException::class);

        $action->executeWithinTransaction(new ReserveGameNumbersData(
            gameId: '01900000-0000-7000-8000-000000000000',
            userId: 1,
            gameNumberIds: ['01900000-0000-7000-8000-000000000001'],
        ));
    }
}
