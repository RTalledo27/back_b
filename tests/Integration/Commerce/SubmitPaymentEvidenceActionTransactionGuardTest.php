<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Modules\Commerce\Application\Actions\SubmitPaymentEvidenceAction;
use App\Modules\Commerce\Application\DTOs\StoredEvidenceData;
use App\Modules\Commerce\Application\DTOs\SubmitPaymentEvidenceData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class SubmitPaymentEvidenceActionTransactionGuardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_execute_within_transaction_fails_without_active_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $action = $this->app->make(SubmitPaymentEvidenceAction::class);

        $this->expectException(LogicException::class);

        $action->executeWithinTransaction(
            new SubmitPaymentEvidenceData(orderId: 'o', userId: 1),
            new StoredEvidenceData(
                documentId: 'd', disk: 'payment_evidences', path: 'p/d.pdf',
                originalFilename: 'r.pdf', detectedMimeType: 'application/pdf',
                sizeBytes: 1, sha256: str_repeat('a', 64),
            ),
        );
    }
}
