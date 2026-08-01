<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Modules\Commerce\Domain\Enums\WinnerPayoutDisputeStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutReceiptStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutReconciliationStatus;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use App\Modules\Commerce\Domain\Models\WinnerPayoutReceipt;
use App\Modules\Commerce\Domain\Models\WinnerPayoutReconciliation;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Phase114WinnerSettlementArchitectureTest extends TestCase
{
    #[Test]
    public function settlement_lifecycle_has_explicit_states_and_no_whatsapp_or_gateway(): void
    {
        self::assertSame(['pending', 'confirmed', 'window_expired'], array_column(WinnerPayoutReceiptStatus::cases(), 'value'));
        self::assertSame(['open', 'under_review', 'resolved', 'cancelled'], array_column(WinnerPayoutDisputeStatus::cases(), 'value'));
        self::assertSame(['pending', 'matched', 'discrepancy', 'corrected'], array_column(WinnerPayoutReconciliationStatus::cases(), 'value'));

        $source = $this->sources([
            'app/Modules/Commerce/Application/Actions/ConfirmWinnerPayoutReceiptAction.php',
            'app/Modules/Commerce/Application/Actions/OpenWinnerPayoutDisputeAction.php',
            'app/Modules/Commerce/Application/Actions/ReconcileWinnerPayoutAction.php',
            'app/Modules/Commerce/Application/Actions/CloseGameFinancialAction.php',
            'app/Modules/Commerce/Domain/Models/WinnerPayoutReceipt.php',
            'app/Modules/Commerce/Domain/Models/WinnerPayoutDispute.php',
            'app/Modules/Commerce/Domain/Models/WinnerPayoutReconciliation.php',
        ]);

        foreach (['WhatsApp', 'Twilio', 'gateway', 'Http::', 'curl_exec', 'notify(', 'Mail::'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    #[Test]
    public function resources_and_models_do_not_expose_encrypted_storage(): void
    {
        self::assertContains('description_encrypted', (new WinnerPayoutDispute)->getHidden());
        self::assertContains('notes_encrypted', (new WinnerPayoutReconciliation)->getHidden());
        self::assertContains('idempotency_key_hash', (new WinnerPayoutReceipt)->getHidden());

        $resources = $this->sources([
            'app/Modules/Commerce/Presentation/Http/Resources/Admin/AdminWinnerPayoutDisputeResource.php',
            'app/Modules/Commerce/Presentation/Http/Resources/Admin/AdminWinnerPayoutReconciliationResource.php',
            'app/Modules/Commerce/Presentation/Http/Resources/Admin/AdminGameFinancialClosureResource.php',
        ]);

        foreach (['description_encrypted', 'notes_encrypted', 'external_reference_encrypted', 'idempotency_key_hash'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $resources);
        }
    }

    #[Test]
    public function settlement_routes_are_scoped_and_mutations_are_idempotent(): void
    {
        $expected = [
            'me.winnings.receipt.confirm' => ['POST', 'throttle:winner-payout.receipt'],
            'me.winnings.dispute.store' => ['POST', 'throttle:winner-payout.dispute'],
            'admin.winner-payout-disputes.review' => ['POST', 'throttle:admin.winner-payout-dispute'],
            'admin.winner-payout-disputes.resolve' => ['POST', 'throttle:admin.winner-payout-dispute'],
            'admin.winner-payouts.reconcile' => ['POST', 'throttle:admin.winner-payout-reconcile'],
            'admin.games.financial-close' => ['POST', 'throttle:admin.financial-close'],
        ];

        foreach ($expected as $name => [$method, $throttle]) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertContains($method, $route->methods(), $name);
            self::assertContains('idempotent', $route->gatherMiddleware(), $name);
            self::assertContains($throttle, $route->gatherMiddleware(), $name);
            self::assertContains('auth:sanctum', $route->gatherMiddleware(), $name);
        }
    }

    #[Test]
    public function actions_control_transactions_and_scheduler_only_expires_receipts(): void
    {
        $actions = $this->sources(['app/Modules/Commerce/Application/Actions']);
        $scheduler = file_get_contents(base_path('routes/console.php')) ?: '';

        self::assertStringContainsString('assertTransaction', $actions);
        self::assertStringContainsString('lockForUpdate', $actions);
        self::assertStringContainsString('ExpireWinnerPayoutReceiptsJob', $scheduler);
        self::assertStringNotContainsString('CloseGameFinancialAction', $scheduler);
    }

    private function sources(array $paths): string
    {
        $source = '';
        foreach ($paths as $path) {
            $root = base_path($path);
            $files = is_dir($root)
                ? array_map(static fn (\SplFileInfo $file): string => $file->getPathname(), iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root))))
                : [$root];
            foreach ($files as $file) {
                if (str_ends_with($file, '.php')) {
                    $source .= file_get_contents($file) ?: '';
                }
            }
        }

        return $source;
    }
}
