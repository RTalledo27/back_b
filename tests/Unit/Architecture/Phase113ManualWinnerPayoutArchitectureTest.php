<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Presentation\Http\Controllers\Admin\ListWinnerPayoutsController;
use App\Modules\Commerce\Presentation\Http\Requests\Admin\ListWinnerPayoutsRequest;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Phase113ManualWinnerPayoutArchitectureTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../app/Modules/Commerce';

    #[Test]
    public function lifecycle_has_explicit_legacy_and_dual_control_states(): void
    {
        self::assertSame([
            'legacy_registered', 'draft', 'awaiting_approval', 'approved',
            'processing', 'paid', 'failed', 'cancelled',
        ], array_map(static fn (WinnerPayoutStatus $status): string => $status->value, WinnerPayoutStatus::cases()));
    }

    #[Test]
    public function historical_migration_is_not_a_paid_backfill(): void
    {
        $source = file_get_contents(__DIR__.'/../../../database/migrations/2026_08_01_000000_extend_winner_payouts_for_dual_control.php') ?: '';

        self::assertStringContainsString("'legacy_registered'", $source);
        self::assertStringContainsString("'status' => 'legacy_registered'", $source);
        self::assertStringNotContainsString("'status' => 'paid'", $source);
    }

    #[Test]
    public function create_action_requires_verified_claim_reserved_funding_and_backend_amount(): void
    {
        $source = $this->source('Application/Actions/CreateWinnerPayoutAction.php');

        foreach (['GameStatus::Completed', 'GamePrizeFundingStatus::Reserved', 'WinnerClaimStatus::Verified', 'prize_cents', 'currency'] as $term) {
            self::assertStringContainsString($term, $source);
        }
    }

    #[Test]
    public function actor_separation_and_paid_preconditions_are_enforced_in_actions(): void
    {
        $source = str_replace($this->source('Application/Actions/ProcessWinnerPayoutAction.php'), '', $this->allSources('Application/Actions'));

        foreach (['created_by_user_id', 'approved_by_user_id', 'executed_by_user_id', 'executionEvidenceRequired', 'processingAttemptRequired'] as $term) {
            self::assertStringContainsString($term, $source);
        }
    }

    #[Test]
    public function destinations_attempts_documents_and_events_are_append_only_or_encrypted(): void
    {
        foreach ([
            'Domain/Models/WinnerPayoutDestination.php',
            'Domain/Models/WinnerPayoutExecutionAttempt.php',
            'Domain/Models/WinnerPayoutEvent.php',
            'Domain/Models/WinnerPayoutDocument.php',
        ] as $file) {
            $source = $this->source($file);
            self::assertStringContainsString('ImmutableModelException', $source, $file);
        }

        self::assertStringContainsString('encrypted', $this->source('Domain/Models/WinnerPayoutDestination.php'));
        self::assertStringContainsString('external_reference_encrypted', $this->source('Domain/Models/WinnerPayoutExecutionAttempt.php'));
    }

    #[Test]
    public function resources_do_not_expose_sensitive_storage_or_encrypted_fields(): void
    {
        $source = $this->sources([
            'Presentation/Http/Resources/Admin/AdminWinnerPayoutResource.php',
            'Presentation/Http/Resources/Admin/AdminWinnerPayoutExecutionAttemptResource.php',
            'Presentation/Http/Resources/Admin/AdminWinnerPayoutSensitiveResource.php',
            'Presentation/Http/Resources/Admin/WinnerPayoutResource.php',
        ]);

        foreach (['destination_payload_encrypted', 'external_reference_encrypted', 'idempotency_key_hash', 'request_fingerprint', "'path'", "'sha256'"] as $term) {
            self::assertStringNotContainsString($term, $source);
        }
    }

    #[Test]
    public function all_new_mutations_are_authenticated_admin_idempotent_routes(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'winner-payouts') || $route->methods() === ['GET', 'HEAD']) {
                continue;
            }

            self::assertContains('auth:sanctum', $route->gatherMiddleware(), $route->uri());
            self::assertContains('admin', $route->gatherMiddleware(), $route->uri());
            self::assertContains('idempotent', $route->gatherMiddleware(), $route->uri());
            self::assertContains('throttle:admin.winner-payout', $route->gatherMiddleware(), $route->uri());
        }
    }

    #[Test]
    public function administrative_listing_uses_a_validated_request_and_bounded_pagination(): void
    {
        $parameter = (new \ReflectionMethod(ListWinnerPayoutsController::class, '__invoke'))->getParameters()[0];

        self::assertSame(ListWinnerPayoutsRequest::class, (string) $parameter->getType());
        self::assertStringContainsString("'max:100'", $this->source('Presentation/Http/Requests/Admin/ListWinnerPayoutsRequest.php'));
    }

    #[Test]
    public function association_constraints_and_rollback_guard_protect_lifecycle_data(): void
    {
        $references = $this->source('../../../database/migrations/2026_08_01_000005_add_winner_payout_reference_foreign_keys.php');
        $lifecycle = $this->source('../../../database/migrations/2026_08_01_000000_extend_winner_payouts_for_dual_control.php');

        self::assertStringContainsString('winner_payout_documents_attempt_payout_foreign', $references);
        self::assertStringContainsString('winner_payouts_current_destination_payout_foreign', $references);
        self::assertStringContainsString('non-legacy payout rows', $lifecycle);
    }

    #[Test]
    public function legacy_write_endpoint_has_a_new_flow_guard_and_new_code_has_no_outbox_or_notifications(): void
    {
        self::assertStringContainsString('legacyWriteDisabled', $this->source('Presentation/Http/Controllers/Admin/ProcessWinnerPayoutController.php'));
        $source = $this->sources([
            'Application/Actions/ApproveWinnerPayoutAction.php',
            'Application/Actions/CancelWinnerPayoutAction.php',
            'Application/Actions/CreateWinnerPayoutAction.php',
            'Application/Actions/MarkWinnerPayoutFailedAction.php',
            'Application/Actions/MarkWinnerPayoutPaidAction.php',
            'Application/Actions/RejectWinnerPayoutApprovalAction.php',
            'Application/Actions/StartWinnerPayoutExecutionAction.php',
            'Application/Actions/SubmitWinnerPayoutForApprovalAction.php',
            'Application/Actions/UpdateWinnerPayoutDestinationAction.php',
        ]);

        foreach (['RecordOutboxEventAction', 'notify(', 'Mail::', 'Notification::', 'Http::', 'PayoutProvider', 'confirm-receipt', 'financially_closed'] as $term) {
            self::assertStringNotContainsString($term, $source);
        }
    }

    #[Test]
    public function no_public_payout_endpoint_or_external_payment_provider_exists(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'winner-payouts')) {
                self::assertStringContainsString('admin', implode('|', $route->gatherMiddleware()));
            }
        }

        $source = $this->allSources('Application');
        foreach (['GuzzleHttp', 'curl_exec', 'stream_socket_client', 'Http::'] as $term) {
            self::assertStringNotContainsString($term, $source);
        }
    }

    #[Test]
    public function idempotency_and_postgresql_locking_contracts_are_present(): void
    {
        $controllers = $this->allSources('Presentation/Http/Controllers/Admin');
        $actions = $this->allSources('Application/Actions');

        self::assertStringContainsString('IdempotencyContext', $controllers);
        self::assertStringContainsString('lockForUpdate', $actions);
        self::assertStringContainsString('at-least-once', $this->documentation());
        self::assertStringContainsString('No se afirma exactly-once', $this->documentation());
        self::assertStringContainsString('legacy_registered != paid', $this->documentation());
        self::assertStringContainsString('paid != received', $this->documentation());
    }

    private function source(string $relative): string
    {
        return file_get_contents(self::ROOT.'/'.$relative) ?: '';
    }

    /** @param list<string> $relativeFiles */
    private function sources(array $relativeFiles): string
    {
        return implode("\n", array_map(fn (string $file): string => $this->source($file), $relativeFiles));
    }

    /** @param list<string> $excludedFiles */
    private function allSources(string $relativeDirectory, array $excludedFiles = []): string
    {
        $directory = self::ROOT.'/'.$relativeDirectory;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        $source = '';

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && ! in_array($file->getFilename(), $excludedFiles, true)) {
                $source .= "\n".(file_get_contents($file->getPathname()) ?: '');
            }
        }

        return $source;
    }

    private function documentation(): string
    {
        return file_get_contents(__DIR__.'/../../../docs/phase-11.md') ?: '';
    }
}
