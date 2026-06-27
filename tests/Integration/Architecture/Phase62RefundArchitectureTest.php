<?php

declare(strict_types=1);

namespace Tests\Integration\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Structural guards for Phase 6.2 (Refunds).
 * Grep-based — no DB or Laravel boot needed.
 *
 * Key invariants defended here:
 *  1. Only RefundOrderAction transitions game numbers from Sold → Available.
 *     Other actions (Expire, Cancel, Reject) only operate on Reserved numbers.
 *  2. No player-facing controller or action reaches RefundOrderAction.
 *  3. PurchaseAllocation is append-only inside the refund flow.
 *  4. The idempotency_keys table (used by IdempotentCommandExecutor for
 *     payments) is NOT used by the refund flow; refunds use the refunds table.
 *  5. No sensitive fields are written to game_events payload in the action.
 */
final class Phase62RefundArchitectureTest extends TestCase
{
    private const ACTION_ROOT = __DIR__.'/../../../app/Modules/Commerce/Application/Actions';

    private const CONTROLLER_ROOT = __DIR__.'/../../../app/Modules/Commerce/Presentation/Http/Controllers';

    private const REFUND_ACTION = self::ACTION_ROOT.'/RefundOrderAction.php';

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
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

    // ── 1. Sold→Available transition ─────────────────────────────────────────

    public function test_only_refund_action_transitions_sold_numbers_to_available(): void
    {
        $soldToAvailableCallers = [];

        foreach ($this->phpFilesUnder(self::ACTION_ROOT) as $file) {
            $content = file_get_contents($file) ?: '';

            // Any action that calls transitionTo(GameNumberStatus::Available) AND
            // is working on numbers obtained from a Paid-order (Sold) context.
            // Proxy: only RefundOrderAction checks ALLOWED_GAME_STATUSES (a set
            // that includes SalesClosed / Cancelled). Expire/Cancel/Reject never
            // inspect ALLOWED_GAME_STATUSES because they only touch Reserved numbers.
            if (str_contains($content, 'GameNumberStatus::Available')
                && str_contains($content, 'ALLOWED_GAME_STATUSES')) {
                $soldToAvailableCallers[] = basename($file);
            }
        }

        $this->assertSame(
            ['RefundOrderAction.php'],
            $soldToAvailableCallers,
            'Only RefundOrderAction may transition Sold → Available game numbers.',
        );
    }

    public function test_expire_cancel_reject_do_not_reference_allowed_game_statuses(): void
    {
        $disallowedFiles = ['ExpireOrderAction.php', 'CancelOrderAction.php', 'RejectPaymentAction.php'];
        $offenders = [];

        foreach ($this->phpFilesUnder(self::ACTION_ROOT) as $file) {
            if (! in_array(basename($file), $disallowedFiles, true)) {
                continue;
            }
            $content = file_get_contents($file) ?: '';
            if (str_contains($content, 'ALLOWED_GAME_STATUSES')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Expire/Cancel/Reject actions must not reference ALLOWED_GAME_STATUSES — '
            .'they only operate on Reserved numbers.',
        );
    }

    // ── 2. Player controllers cannot reach RefundOrderAction ─────────────────

    public function test_player_controllers_do_not_reference_refund_order_action(): void
    {
        $playerControllerDir = self::CONTROLLER_ROOT.'/Player';
        $offenders = [];

        foreach ($this->phpFilesUnder($playerControllerDir) as $file) {
            $content = file_get_contents($file) ?: '';
            if (str_contains($content, 'RefundOrderAction')
                || str_contains($content, 'RefundOrderController')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Player-facing controllers must not reference RefundOrderAction.',
        );
    }

    public function test_refund_action_is_only_referenced_from_admin_controller(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(self::CONTROLLER_ROOT) as $file) {
            // Admin controller is the one allowed reference
            if (str_contains($file, 'Admin'.DIRECTORY_SEPARATOR.'RefundOrderController.php')) {
                continue;
            }
            $content = file_get_contents($file) ?: '';
            if (str_contains($content, 'RefundOrderAction')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Only RefundOrderController (Admin) may reference RefundOrderAction.',
        );
    }

    // ── 3. PurchaseAllocation is append-only in the refund flow ──────────────

    public function test_refund_action_does_not_modify_purchase_allocation(): void
    {
        $content = file_get_contents(self::REFUND_ACTION) ?: '';

        // The refund flow reads allocations (pluck, get) but must never
        // update or delete them.
        $this->assertFalse(
            (bool) preg_match('/PurchaseAllocation[^;]*->(save|update|delete|forceFill)\(/', $content),
            'RefundOrderAction must not modify PurchaseAllocation records.',
        );
    }

    // ── 4. Refund does NOT use IdempotentCommandExecutor ─────────────────────

    public function test_refund_action_does_not_use_idempotent_command_executor(): void
    {
        $content = file_get_contents(self::REFUND_ACTION) ?: '';

        $this->assertFalse(
            str_contains($content, 'IdempotentCommandExecutor'),
            'RefundOrderAction must not use IdempotentCommandExecutor — '
            .'idempotency is managed via the refunds table directly.',
        );

        $this->assertFalse(
            str_contains($content, 'idempotency_keys'),
            'RefundOrderAction must not reference the idempotency_keys table.',
        );
    }

    // ── 5. No sensitive fields written to game_events payload ────────────────

    public function test_refund_action_does_not_write_secrets_to_game_event_payload(): void
    {
        $content = file_get_contents(self::REFUND_ACTION) ?: '';

        $forbidden = [
            'idempotency_key_hash',
            'request_fingerprint',
            'idempotencyKeyHash',
            'requestFingerprint',
        ];

        // The GameEvent payload array inside the action must not contain
        // any of the forbidden field names.
        $payloadBlock = '';
        if (preg_match('/GameEvent::create\(\[(.*?)\]\)/s', $content, $m)) {
            $payloadBlock = $m[1];
        }

        foreach ($forbidden as $field) {
            $this->assertFalse(
                str_contains($payloadBlock, $field),
                "GameEvent payload must not include '{$field}'.",
            );
        }
    }

    // ── 6. UniqueConstraintViolationException not caught inside transaction ──

    public function test_refund_action_does_not_catch_unique_constraint_violation(): void
    {
        $content = file_get_contents(self::REFUND_ACTION) ?: '';

        $this->assertFalse(
            str_contains($content, 'UniqueConstraintViolationException'),
            'RefundOrderAction must not catch UniqueConstraintViolationException — '
            .'idempotency is checked before INSERT via SELECT FOR UPDATE.',
        );
    }
}
