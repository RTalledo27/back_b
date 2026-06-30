<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Grep-based structural guards for Phase 8.2 (Outbox Infrastructure).
 *
 * Key invariants:
 *  1. RecordOutboxEventAction guards against being called outside a transaction.
 *  2. RecordOutboxEventAction uses ON CONFLICT DO NOTHING, not try/catch on
 *     UniqueConstraintViolationException (which aborts the transaction).
 *  3. OutboxEventDispatcher has no HTTP-layer imports.
 *  4. PaymentApproved outbox payload excludes all sensitive fields.
 *  5. OutboxEventProcessor uses FOR UPDATE SKIP LOCKED for safe claiming.
 *  6. OutboxEventProcessor clears locked_at on success, retryable failure,
 *     and final failure.
 *  7. ProcessOutboxEventsJob implements ShouldQueue with tries = 1.
 */
final class Phase82OutboxArchitectureTest extends TestCase
{
    private const SHARED_APP = __DIR__.'/../../../app/Modules/Shared';

    private const RECORD_ACTION = self::SHARED_APP.'/Application/Actions/RecordOutboxEventAction.php';

    private const DISPATCHER = self::SHARED_APP.'/Infrastructure/Outbox/OutboxEventDispatcher.php';

    private const PROCESSOR = self::SHARED_APP.'/Infrastructure/Outbox/OutboxEventProcessor.php';

    private const JOB = self::SHARED_APP.'/Application/Jobs/ProcessOutboxEventsJob.php';

    private const APPROVE_ACTION = __DIR__.'/../../../app/Modules/Commerce/Application/Actions/ApprovePaymentAction.php';

    private function read(string $path): string
    {
        $this->assertFileExists($path, "Expected file not found: {$path}");

        return (string) file_get_contents($path);
    }

    // ── 1. Transaction guard ─────────────────────────────────────────────────

    public function test_record_outbox_action_checks_transaction_level(): void
    {
        $content = $this->read(self::RECORD_ACTION);

        $this->assertStringContainsString(
            'transactionLevel()',
            $content,
            'RecordOutboxEventAction must guard against being called outside a transaction.'
        );
    }

    // ── 2. ON CONFLICT DO NOTHING, not UniqueConstraintViolationException ────

    public function test_record_outbox_action_uses_on_conflict_do_nothing(): void
    {
        $content = $this->read(self::RECORD_ACTION);

        $this->assertStringContainsString(
            'ON CONFLICT',
            $content,
            'RecordOutboxEventAction must use ON CONFLICT DO NOTHING for deduplication.'
        );

        $this->assertStringContainsString(
            'DO NOTHING',
            $content,
        );
    }

    public function test_record_outbox_action_does_not_catch_unique_constraint_violation(): void
    {
        $content = $this->read(self::RECORD_ACTION);

        // The file may mention UniqueConstraintViolationException in comments, but
        // must NEVER catch it (catching aborts the PostgreSQL transaction).
        $this->assertDoesNotMatchRegularExpression(
            '/catch\s*\([^)]*UniqueConstraintViolationException/',
            $content,
            'RecordOutboxEventAction must not catch UniqueConstraintViolationException — '
            .'catching it means the transaction is already aborted in PostgreSQL.'
        );
    }

    // ── 3. Dispatcher has no HTTP imports ────────────────────────────────────

    public function test_outbox_event_dispatcher_does_not_import_http_layer(): void
    {
        $content = $this->read(self::DISPATCHER);

        $this->assertStringNotContainsString(
            'Illuminate\\Http',
            $content,
            'OutboxEventDispatcher must not import the HTTP layer.'
        );

        $this->assertStringNotContainsString(
            'Request',
            $content,
            'OutboxEventDispatcher must not reference HTTP Request.'
        );
    }

    // ── 4. Approve action outbox payload contains no sensitive fields ─────────

    public function test_outbox_payload_in_approve_action_excludes_sensitive_fields(): void
    {
        $content = $this->read(self::APPROVE_ACTION);

        // Find the payload array passed to recordOutbox->execute()
        $sensitiveFields = [
            'email',
            'phone',
            'evidence_path',
            'disk',
            'sha256',
            'idempotency_key',
            'request_fingerprint',
            'token',
            'bank_account',
            'reviewer_user_id',
        ];

        // Locate the payload array in the recordOutbox call
        if (preg_match('/recordOutbox->execute\((.*?)\);/s', $content, $m)) {
            $callBlock = $m[1];
            foreach ($sensitiveFields as $field) {
                $this->assertStringNotContainsString(
                    "'{$field}'",
                    $callBlock,
                    "Outbox payload must not include field '{$field}'."
                );
            }
        } else {
            $this->fail('Could not locate recordOutbox->execute() call in ApprovePaymentAction.');
        }
    }

    // ── 5. Processor uses FOR UPDATE SKIP LOCKED ─────────────────────────────

    public function test_outbox_processor_uses_for_update_skip_locked(): void
    {
        $content = $this->read(self::PROCESSOR);

        $this->assertStringContainsString(
            'FOR UPDATE SKIP LOCKED',
            $content,
            'OutboxEventProcessor must use FOR UPDATE SKIP LOCKED in the claim query.'
        );
    }

    // ── 6. Processor clears lock on all outcomes ─────────────────────────────

    public function test_outbox_processor_clears_locked_at_on_success(): void
    {
        $content = $this->read(self::PROCESSOR);

        // Check that 'processed_at' is set together with locked_at => null in the same update
        $this->assertMatchesRegularExpression(
            "/'processed_at'.*'locked_at'.*null/s",
            $content,
            'Processor must clear locked_at when marking an event as processed.'
        );
    }

    public function test_outbox_processor_clears_locked_at_on_failure(): void
    {
        $content = $this->read(self::PROCESSOR);

        // The failure-path update array must contain 'locked_at' => null.
        // Use a regex to tolerate varying alignment / spacing.
        $this->assertMatchesRegularExpression(
            "/'locked_at'\s*=>\s*null/",
            $content,
            'Processor must clear locked_at on both success and failure outcomes.'
        );
    }

    // ── 7. Job wires correctly ────────────────────────────────────────────────

    public function test_process_outbox_job_implements_should_queue(): void
    {
        $content = $this->read(self::JOB);

        $this->assertStringContainsString(
            'ShouldQueue',
            $content,
            'ProcessOutboxEventsJob must implement ShouldQueue.'
        );
    }

    public function test_process_outbox_job_has_tries_one(): void
    {
        $content = $this->read(self::JOB);

        $this->assertMatchesRegularExpression(
            '/\$tries\s*=\s*1\b/',
            $content,
            'ProcessOutboxEventsJob must set $tries = 1.'
        );
    }

    public function test_approve_payment_action_records_outbox_event(): void
    {
        $content = $this->read(self::APPROVE_ACTION);

        $this->assertStringContainsString(
            'recordOutbox',
            $content,
            'ApprovePaymentAction must call recordOutbox->execute() to insert the outbox event.'
        );
    }

    public function test_approve_payment_action_does_not_record_outbox_on_replay_branch(): void
    {
        $content = $this->read(self::APPROVE_ACTION);

        // The idempotent replay branch returns early (wasTransitionApplied = false).
        // The $this->recordOutbox->execute() call (actual dispatch) must appear
        // AFTER that early return, not before it.
        $replayReturnPos = strpos($content, 'wasTransitionApplied: false');
        $outboxCallPos = strpos($content, '$this->recordOutbox->execute(');

        $this->assertNotFalse($replayReturnPos, 'Could not find wasTransitionApplied: false in ApprovePaymentAction.');
        $this->assertNotFalse($outboxCallPos, 'Could not find $this->recordOutbox->execute( in ApprovePaymentAction.');
        $this->assertGreaterThan(
            $replayReturnPos,
            $outboxCallPos,
            'The $this->recordOutbox->execute() call must appear after the idempotent replay early-return branch.'
        );
    }
}
