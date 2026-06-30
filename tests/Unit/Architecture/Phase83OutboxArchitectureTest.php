<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Grep-based structural guards for Phase 8.3 (Outbox critical events expansion).
 *
 * Invariants:
 *  1. RejectPaymentAction records outbox event only on new-rejection branch.
 *  2. RefundOrderAction records outbox event only on new-refund branch.
 *  3. ProcessWinnerPayoutAction records outbox event only on new-payout branch.
 *  4. DrawGameNumberAction / resolveWinner records outbox event for game_winner_declared.
 *  5. All four action payloads exclude sensitive fields.
 *  6. Dispatcher handles exactly 5 event types; unknown throws RuntimeException.
 *  7. No UniqueConstraintViolationException used as control flow in any action.
 *  8. No real notification / mailer / SMS / gateway calls in Phase 8.3 handlers.
 */
final class Phase83OutboxArchitectureTest extends TestCase
{
    private const COMMERCE_ACTIONS = __DIR__.'/../../../app/Modules/Commerce/Application/Actions';

    private const GAME_ACTIONS = __DIR__.'/../../../app/Modules/RepeatNumberBingo/Application/Actions';

    private const DISPATCHER = __DIR__.'/../../../app/Modules/Shared/Infrastructure/Outbox/OutboxEventDispatcher.php';

    private const REJECT_ACTION = self::COMMERCE_ACTIONS.'/RejectPaymentAction.php';

    private const REFUND_ACTION = self::COMMERCE_ACTIONS.'/RefundOrderAction.php';

    private const PAYOUT_ACTION = self::COMMERCE_ACTIONS.'/ProcessWinnerPayoutAction.php';

    private const DRAW_ACTION = self::GAME_ACTIONS.'/DrawGameNumberAction.php';

    private function read(string $path): string
    {
        $this->assertFileExists($path, "Expected file not found: {$path}");

        return (string) file_get_contents($path);
    }

    // ── 1. RejectPaymentAction ────────────────────────────────────────────────

    public function test_reject_payment_action_records_outbox_event(): void
    {
        $content = $this->read(self::REJECT_ACTION);

        $this->assertStringContainsString(
            'recordOutbox',
            $content,
            'RejectPaymentAction must call recordOutbox->execute() to insert the outbox event.'
        );
        $this->assertStringContainsString('payment_rejected', $content);
    }

    public function test_reject_payment_action_payload_excludes_sensitive_fields(): void
    {
        $content = $this->read(self::REJECT_ACTION);

        if (preg_match('/recordOutbox->execute\((.*?)\);/s', $content, $m)) {
            $callBlock = $m[1];
            foreach (['email', 'phone', 'reason', 'reviewer_user_id', 'token', 'bank_account'] as $field) {
                $this->assertStringNotContainsString(
                    "'{$field}'",
                    $callBlock,
                    "RejectPaymentAction outbox payload must not include '{$field}'."
                );
            }
        } else {
            $this->fail('Could not locate recordOutbox->execute() call in RejectPaymentAction.');
        }
    }

    public function test_reject_payment_action_does_not_record_outbox_on_replay_branch(): void
    {
        $content = $this->read(self::REJECT_ACTION);

        $replayReturnPos = strpos($content, 'wasTransitionApplied: false');
        $outboxCallPos = strpos($content, 'recordOutbox->execute(');

        $this->assertNotFalse($replayReturnPos, 'Could not find replay early-return in RejectPaymentAction.');
        $this->assertNotFalse($outboxCallPos, 'Could not find recordOutbox->execute() in RejectPaymentAction.');
        $this->assertGreaterThan(
            $replayReturnPos,
            $outboxCallPos,
            'The recordOutbox->execute() call must appear after the idempotent replay early-return branch.'
        );
    }

    // ── 2. RefundOrderAction ──────────────────────────────────────────────────

    public function test_refund_order_action_records_outbox_event(): void
    {
        $content = $this->read(self::REFUND_ACTION);

        $this->assertStringContainsString('recordOutbox', $content);
        $this->assertStringContainsString('order_refunded', $content);
    }

    public function test_refund_order_action_payload_excludes_sensitive_fields(): void
    {
        $content = $this->read(self::REFUND_ACTION);

        if (preg_match('/recordOutbox->execute\((.*?)\);/s', $content, $m)) {
            $callBlock = $m[1];
            foreach (['email', 'phone', 'reason', 'idempotency_key_hash', 'request_fingerprint', 'path', 'disk', 'sha256'] as $field) {
                $this->assertStringNotContainsString(
                    "'{$field}'",
                    $callBlock,
                    "RefundOrderAction outbox payload must not include '{$field}'."
                );
            }
        } else {
            $this->fail('Could not locate recordOutbox->execute() call in RefundOrderAction.');
        }
    }

    // ── 3. ProcessWinnerPayoutAction ──────────────────────────────────────────

    public function test_payout_action_records_outbox_event(): void
    {
        $content = $this->read(self::PAYOUT_ACTION);

        $this->assertStringContainsString('recordOutbox', $content);
        $this->assertStringContainsString('winner_payout_registered', $content);
    }

    public function test_payout_action_payload_excludes_sensitive_fields(): void
    {
        $content = $this->read(self::PAYOUT_ACTION);

        if (preg_match('/recordOutbox->execute\((.*?)\);/s', $content, $m)) {
            $callBlock = $m[1];
            foreach (['path', 'disk', 'sha256', 'original_filename', 'external_reference', 'idempotency_key_hash', 'email', 'phone'] as $field) {
                $this->assertStringNotContainsString(
                    "'{$field}'",
                    $callBlock,
                    "ProcessWinnerPayoutAction outbox payload must not include '{$field}'."
                );
            }
        } else {
            $this->fail('Could not locate recordOutbox->execute() call in ProcessWinnerPayoutAction.');
        }
    }

    // ── 4. DrawGameNumberAction / resolveWinner ───────────────────────────────

    public function test_draw_action_records_game_winner_declared_outbox_event(): void
    {
        $content = $this->read(self::DRAW_ACTION);

        $this->assertStringContainsString('game_winner_declared', $content);
        $this->assertStringContainsString('recordOutbox', $content);
    }

    public function test_draw_action_outbox_insert_is_in_resolve_winner_not_outside(): void
    {
        $content = $this->read(self::DRAW_ACTION);

        // The outbox call must be inside resolveWinner, after the GameEvent::create calls.
        $resolveWinnerPos = strpos($content, 'private function resolveWinner(');
        $outboxPos = strpos($content, "'game_winner_declared'");

        $this->assertNotFalse($resolveWinnerPos, 'resolveWinner() method not found in DrawGameNumberAction.');
        $this->assertNotFalse($outboxPos, 'game_winner_declared string not found in DrawGameNumberAction.');
        $this->assertGreaterThan(
            $resolveWinnerPos,
            $outboxPos,
            'game_winner_declared outbox call must be inside resolveWinner().'
        );
    }

    public function test_draw_action_payload_excludes_pii(): void
    {
        $content = $this->read(self::DRAW_ACTION);

        if (preg_match("/'game_winner_declared'(.*?)\\);/s", $content, $m)) {
            $segment = $m[1];
            foreach (['email', 'phone', 'reason', 'name', 'path', 'disk', 'sha256'] as $field) {
                $this->assertStringNotContainsString(
                    "'{$field}'",
                    $segment,
                    "DrawGameNumberAction outbox payload must not include '{$field}'."
                );
            }
        } else {
            $this->fail('Could not locate game_winner_declared outbox call in DrawGameNumberAction.');
        }
    }

    // ── 5. Dispatcher handles 5 types, rejects unknown ────────────────────────

    public function test_dispatcher_handles_all_phase83_event_types(): void
    {
        $content = $this->read(self::DISPATCHER);

        $expected = [
            'payment_approved',
            'payment_rejected',
            'order_refunded',
            'winner_payout_registered',
            'game_winner_declared',
        ];

        foreach ($expected as $type) {
            $this->assertStringContainsString(
                "'{$type}'",
                $content,
                "OutboxEventDispatcher must handle event type '{$type}'."
            );
        }
    }

    public function test_dispatcher_throws_runtime_exception_for_unknown_type(): void
    {
        $content = $this->read(self::DISPATCHER);

        $this->assertStringContainsString(
            'RuntimeException',
            $content,
            'OutboxEventDispatcher must throw RuntimeException for unknown event types.'
        );

        $this->assertStringContainsString(
            'default =>',
            $content,
            'OutboxEventDispatcher must have a default case that throws.'
        );
    }

    // ── 6. No UniqueConstraintViolationException as control flow ─────────────

    public function test_phase83_actions_do_not_catch_unique_constraint_violation(): void
    {
        $actionFiles = [
            'RejectPaymentAction' => self::REJECT_ACTION,
            'RefundOrderAction' => self::REFUND_ACTION,
            'ProcessWinnerPayoutAction' => self::PAYOUT_ACTION,
            'DrawGameNumberAction' => self::DRAW_ACTION,
        ];

        foreach ($actionFiles as $name => $path) {
            $content = $this->read($path);
            $this->assertDoesNotMatchRegularExpression(
                '/catch\s*\([^)]*UniqueConstraintViolationException/',
                $content,
                "{$name} must not catch UniqueConstraintViolationException — doing so aborts the PostgreSQL transaction."
            );
        }
    }

    // ── 7. Dispatcher has no real notification / mailer / gateway calls ───────

    public function test_phase83_dispatcher_handlers_do_not_send_real_notifications(): void
    {
        $content = $this->read(self::DISPATCHER);

        $forbidden = [
            'Mail::',
            'Notification::',
            'Sms::',
            'Http::',
            'WhatsApp',
            'gateway',
            'webhook',
            'curl',
        ];

        foreach ($forbidden as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $content,
                "OutboxEventDispatcher Phase 8.3 handlers must not call '{$pattern}'. Real providers come in Phase 9."
            );
        }
    }
}
