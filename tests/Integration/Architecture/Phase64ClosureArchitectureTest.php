<?php

declare(strict_types=1);

namespace Tests\Integration\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Structural guards for Phase 6.4 (Closure — cross-cutting architectural invariants).
 * Grep-based — no DB or Laravel boot needed.
 *
 * Key invariants defended here:
 *  1. Financial write controllers delegate the transaction boundary to Actions.
 *  2. Financial read controllers are transaction-free.
 *  3. Admin Resources for Phase 6 are pure DTO transformers — no Eloquent queries.
 *  4. RefundResource does not expose internal idempotency hash fields.
 *  5. WinnerPayoutResource does not expose internal document storage fields.
 *  6. Production code (app/) contains no test-only infrastructure (Storage::fake).
 *  7. RefundOrderAction has the transaction guard in executeWithinTransaction.
 *  8. Both financial Actions own their own DB::transaction boundary.
 */
final class Phase64ClosureArchitectureTest extends TestCase
{
    private const RESOURCE_ADMIN_ROOT = __DIR__.'/../../../app/Modules/Commerce/Presentation/Http/Resources/Admin';

    private const ACTION_ROOT = __DIR__.'/../../../app/Modules/Commerce/Application/Actions';

    private const CONTROLLER_ADMIN_ROOT = __DIR__.'/../../../app/Modules/Commerce/Presentation/Http/Controllers/Admin';

    private const APP_ROOT = __DIR__.'/../../../app';

    private const REFUND_CONTROLLER = self::CONTROLLER_ADMIN_ROOT.'/RefundOrderController.php';

    private const PAYOUT_CONTROLLER = self::CONTROLLER_ADMIN_ROOT.'/ProcessWinnerPayoutController.php';

    private const SHOW_REFUND_CONTROLLER = self::CONTROLLER_ADMIN_ROOT.'/ShowOrderRefundController.php';

    private const SHOW_PAYOUT_CONTROLLER = self::CONTROLLER_ADMIN_ROOT.'/ShowWinnerPayoutController.php';

    private const REFUND_RESOURCE = self::RESOURCE_ADMIN_ROOT.'/RefundResource.php';

    private const PAYOUT_RESOURCE = self::RESOURCE_ADMIN_ROOT.'/WinnerPayoutResource.php';

    private const REFUND_ACTION = self::ACTION_ROOT.'/RefundOrderAction.php';

    private const PAYOUT_ACTION = self::ACTION_ROOT.'/ProcessWinnerPayoutAction.php';

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        $files = [];
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    // ── 1. Financial write controllers do NOT open DB transactions ────────────

    /**
     * RefundOrderController and ProcessWinnerPayoutController must NOT call
     * DB::transaction — that boundary belongs to the Action layer.
     */
    public function test_financial_write_controllers_do_not_call_db_transaction(): void
    {
        $offenders = [];

        foreach ([self::REFUND_CONTROLLER, self::PAYOUT_CONTROLLER] as $file) {
            $content = file_get_contents($file) ?: '';
            if (str_contains($content, 'DB::transaction')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Financial write controllers must not open transactions directly — '
            .'the Action owns the transaction boundary.',
        );
    }

    // ── 2. Financial read controllers do NOT open DB transactions ─────────────

    /**
     * ShowOrderRefundController and ShowWinnerPayoutController are read-only —
     * they must not open transactions.
     */
    public function test_financial_read_controllers_do_not_call_db_transaction(): void
    {
        $offenders = [];

        foreach ([self::SHOW_REFUND_CONTROLLER, self::SHOW_PAYOUT_CONTROLLER] as $file) {
            $content = file_get_contents($file) ?: '';
            if (str_contains($content, 'DB::transaction')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Financial read controllers must be transaction-free — they query only, never mutate.',
        );
    }

    // ── 3. Phase 6 Admin Resources are pure DTO transformers ─────────────────

    /**
     * RefundResource and WinnerPayoutResource must be pure DTO transformers.
     * Eloquent queries inside toArray() create N+1 risks and hidden DB coupling.
     */
    public function test_phase6_admin_resources_do_not_contain_eloquent_queries(): void
    {
        $eloquentSignals = ['->find(', '->where(', '->first(', '->get(', '->pluck(', '::query()'];
        $offenders = [];

        foreach ([self::REFUND_RESOURCE, self::PAYOUT_RESOURCE] as $file) {
            $content = file_get_contents($file) ?: '';
            foreach ($eloquentSignals as $signal) {
                if (str_contains($content, $signal)) {
                    $offenders[] = basename($file)." contains '".$signal."'";
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Phase 6 Admin Resources must be pure DTO transformers — '
            .'no Eloquent queries inside toArray().',
        );
    }

    // ── 4. RefundResource does NOT expose internal idempotency fields ─────────

    /**
     * idempotency_key_hash and request_fingerprint are server-side secrets used
     * to detect replay vs. conflict. They must never appear in the HTTP response.
     */
    public function test_refund_resource_does_not_expose_idempotency_hashes(): void
    {
        $content = file_get_contents(self::REFUND_RESOURCE) ?: '';

        $forbidden = [
            'idempotency_key_hash',
            'request_fingerprint',
            'idempotencyKeyHash',
            'requestFingerprint',
        ];

        foreach ($forbidden as $field) {
            $this->assertFalse(
                str_contains($content, $field),
                "RefundResource must not expose '{$field}' — "
                .'internal idempotency fields must stay server-side.',
            );
        }
    }

    // ── 5. WinnerPayoutResource does NOT expose document storage internals ────

    /**
     * Document disk, path, and sha256 are internal storage details that must
     * never appear in the HTTP response (they reveal filesystem layout).
     * The idempotency/fingerprint hashes are also excluded.
     */
    public function test_winner_payout_resource_does_not_expose_document_storage_fields(): void
    {
        $content = file_get_contents(self::PAYOUT_RESOURCE) ?: '';

        $forbidden = [
            'documentDisk',
            'documentPath',
            'documentSha256',
            'idempotency_key_hash',
            'request_fingerprint',
            'idempotencyKeyHash',
            'requestFingerprint',
        ];

        foreach ($forbidden as $field) {
            $this->assertFalse(
                str_contains($content, $field),
                "WinnerPayoutResource must not expose '{$field}' — "
                .'internal storage/idempotency fields must stay server-side.',
            );
        }
    }

    // ── 6. No test infrastructure in production code ──────────────────────────

    /**
     * Storage::fake() must never appear in production app/ code.
     * Test fakes belong exclusively in tests/.
     */
    public function test_no_storage_fake_in_production_code(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(self::APP_ROOT) as $file) {
            $content = file_get_contents($file) ?: '';
            if (str_contains($content, 'Storage::fake')) {
                $offenders[] = str_replace(
                    realpath(self::APP_ROOT).DIRECTORY_SEPARATOR,
                    'app/',
                    realpath($file) ?: $file,
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Storage::fake() must not appear in production app/ code — '
            .'test fakes belong in tests/.',
        );
    }

    // ── 7. RefundOrderAction has the transaction guard ────────────────────────

    /**
     * executeWithinTransaction must verify it is called from within an active
     * transaction. This prevents accidental bare calls that bypass the lock order.
     */
    public function test_refund_action_has_transaction_guard(): void
    {
        $content = file_get_contents(self::REFUND_ACTION) ?: '';

        $this->assertTrue(
            str_contains($content, 'DB::transactionLevel()'),
            'RefundOrderAction::executeWithinTransaction must guard against being '
            .'called outside a transaction (DB::transactionLevel() === 0 check).',
        );

        $this->assertTrue(
            str_contains($content, 'executeWithinTransaction'),
            'RefundOrderAction must have an executeWithinTransaction method '
            .'to enforce the transaction guard.',
        );
    }

    // ── 8. Both financial Actions own their DB::transaction boundary ──────────

    /**
     * The Action layer is the single owner of DB::transaction for financial writes.
     * Neither the controller nor any upstream caller should open the transaction.
     */
    public function test_financial_actions_own_database_transaction_boundary(): void
    {
        foreach ([self::REFUND_ACTION, self::PAYOUT_ACTION] as $file) {
            $content = file_get_contents($file) ?: '';
            $this->assertTrue(
                str_contains($content, 'DB::transaction'),
                basename($file).' must call DB::transaction() — '
                .'the Action owns the transaction boundary.',
            );
        }
    }
}
