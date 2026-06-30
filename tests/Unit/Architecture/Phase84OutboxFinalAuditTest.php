<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Grep-based final audit guards for Phase 8.4 (Outbox closure).
 *
 * This test completes the structural verification started in
 * Phase82OutboxArchitectureTest and Phase83OutboxArchitectureTest.
 *
 * New invariants verified here:
 *  1.  No HTTP routes for outbox exist in api.php or web.php.
 *  2.  ProcessOutboxEventsJob is scheduled every minute with withoutOverlapping.
 *  3.  Migration does not use gen_random_uuid() (must use uuid7 or app-generated IDs).
 *  4.  Migration has JSONB object check constraint.
 *  5.  Migration has partial pending index for the worker query.
 *  6.  Migration has partial unique index for deduplication.
 *  7.  No forbidden event types are referenced in outbox calls across all action files.
 *  8.  OutboxEventProcessor tracks all required state fields.
 *  9.  OutboxEventProcessor implements stale lock recovery.
 * 10.  OutboxEventProcessor implements exponential backoff via next_attempt_at.
 * 11.  No Mail/Notification/Sms/Http calls in the full Outbox module.
 * 12.  RecordOutboxEventAction is not called from any Controller.
 * 13.  Dispatcher covers exactly 5 handler methods (= 5 allowed event types).
 * 14.  Forbidden event types are NOT integrated into outbox yet.
 */
final class Phase84OutboxFinalAuditTest extends TestCase
{
    // ── Paths ────────────────────────────────────────────────────────────────

    private const OUTBOX_MODULE = __DIR__.'/../../../app/Modules/Shared';

    private const MIGRATION = __DIR__.'/../../../database/migrations/2026_06_30_100000_create_outbox_events_table.php';

    private const CONSOLE = __DIR__.'/../../../routes/console.php';

    private const API_ROUTES = __DIR__.'/../../../routes/api.php';

    private const WEB_ROUTES = __DIR__.'/../../../routes/web.php';

    private const PROCESSOR = self::OUTBOX_MODULE.'/Infrastructure/Outbox/OutboxEventProcessor.php';

    private const DISPATCHER = self::OUTBOX_MODULE.'/Infrastructure/Outbox/OutboxEventDispatcher.php';

    private const RECORD_ACTION = self::OUTBOX_MODULE.'/Application/Actions/RecordOutboxEventAction.php';

    private const COMMERCE_ACTIONS = __DIR__.'/../../../app/Modules/Commerce/Application/Actions';

    private const GAME_ACTIONS = __DIR__.'/../../../app/Modules/RepeatNumberBingo/Application/Actions';

    private const HTTP_CONTROLLERS = __DIR__.'/../../../app/Modules';

    /** Allowed event types — exactly these 5, no more. */
    private const ALLOWED_EVENT_TYPES = [
        'payment_approved',
        'payment_rejected',
        'order_refunded',
        'winner_payout_registered',
        'game_winner_declared',
    ];

    /** Event types that must NOT be integrated into outbox in Phases 8.x. */
    private const FORBIDDEN_OUTBOX_TYPES = [
        'game_completed',
        'game_cancelled',
        'order_reservations_expired',
        'order_cancelled_by_user',
        'game_started',
        'payment_evidence_submitted',
        'game_number_drawn',
        'game_paused',
        'game_resumed',
    ];

    private function read(string $path): string
    {
        $this->assertFileExists($path, "Expected file not found: {$path}");

        return (string) file_get_contents($path);
    }

    // ── 1. No HTTP routes for outbox ─────────────────────────────────────────

    public function test_api_routes_have_no_outbox_endpoints(): void
    {
        $content = $this->read(self::API_ROUTES);

        $this->assertStringNotContainsString(
            'outbox',
            strtolower($content),
            'api.php must not define any outbox-specific HTTP routes.'
        );
    }

    public function test_web_routes_have_no_outbox_endpoints(): void
    {
        $content = $this->read(self::WEB_ROUTES);

        $this->assertStringNotContainsString(
            'outbox',
            strtolower($content),
            'web.php must not define any outbox-specific HTTP routes.'
        );
    }

    // ── 2. Scheduler wiring ───────────────────────────────────────────────────

    public function test_process_outbox_job_is_scheduled_every_minute(): void
    {
        $content = $this->read(self::CONSOLE);

        $this->assertStringContainsString(
            'ProcessOutboxEventsJob',
            $content,
            'routes/console.php must schedule ProcessOutboxEventsJob.'
        );

        $this->assertStringContainsString(
            'everyMinute()',
            $content,
            'ProcessOutboxEventsJob must be scheduled everyMinute().'
        );
    }

    public function test_process_outbox_scheduler_has_without_overlapping(): void
    {
        $content = $this->read(self::CONSOLE);

        $outboxSection = substr($content, strpos($content, 'ProcessOutboxEventsJob') ?? 0);

        $this->assertStringContainsString(
            'withoutOverlapping',
            $outboxSection,
            'The ProcessOutboxEventsJob schedule must use withoutOverlapping() to prevent concurrent runs.'
        );
    }

    // ── 3–6. Migration structure ──────────────────────────────────────────────

    public function test_migration_does_not_use_gen_random_uuid(): void
    {
        $content = $this->read(self::MIGRATION);

        $this->assertStringNotContainsString(
            'gen_random_uuid()',
            $content,
            'outbox_events migration must not use gen_random_uuid() — IDs are app-generated (uuid7).'
        );
    }

    public function test_migration_has_jsonb_object_check_constraint(): void
    {
        $content = $this->read(self::MIGRATION);

        $this->assertStringContainsString(
            "jsonb_typeof(payload) = 'object'",
            $content,
            'outbox_events migration must enforce that payload is a JSONB object, not an array or scalar.'
        );
    }

    public function test_migration_has_partial_pending_index(): void
    {
        $content = $this->read(self::MIGRATION);

        $this->assertStringContainsString(
            'outbox_events_pending_idx',
            $content,
            'outbox_events migration must create the pending index for efficient worker queries.'
        );

        $this->assertStringContainsString(
            'WHERE processed_at IS NULL AND failed_at IS NULL',
            $content,
            'The pending index must be partial: WHERE processed_at IS NULL AND failed_at IS NULL.'
        );
    }

    public function test_migration_has_partial_dedup_unique_index(): void
    {
        $content = $this->read(self::MIGRATION);

        $this->assertStringContainsString(
            'outbox_events_dedup_unprocessed_idx',
            $content,
            'outbox_events migration must create the deduplication partial unique index.'
        );

        $this->assertStringContainsString(
            'deduplication_key IS NOT NULL AND processed_at IS NULL',
            $content,
            'The dedup index must be partial: WHERE deduplication_key IS NOT NULL AND processed_at IS NULL.'
        );

        $this->assertMatchesRegularExpression(
            '/CREATE UNIQUE INDEX.*dedup/s',
            $content,
            'The dedup index must be UNIQUE.'
        );
    }

    // ── 7. Forbidden event types not yet integrated ───────────────────────────

    public function test_forbidden_event_types_are_not_in_outbox_calls(): void
    {
        $actionDirs = [self::COMMERCE_ACTIONS, self::GAME_ACTIONS];

        foreach ($actionDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $files = glob($dir.'/*.php') ?: [];

            foreach ($files as $file) {
                $content = (string) file_get_contents($file);

                if (! str_contains($content, 'recordOutbox')) {
                    continue;
                }

                foreach (self::FORBIDDEN_OUTBOX_TYPES as $type) {
                    $this->assertStringNotContainsString(
                        "'{$type}'",
                        $content,
                        basename($file)." must not integrate forbidden outbox event type '{$type}' in Phase 8."
                    );
                }
            }
        }

        $this->assertTrue(true, 'No forbidden event types found in outbox calls.');
    }

    // ── 8. Processor state fields ─────────────────────────────────────────────

    public function test_outbox_processor_tracks_all_required_state_fields(): void
    {
        $content = $this->read(self::PROCESSOR);

        // String-key fields written in update arrays:
        $arrayKeyFields = [
            'attempts',
            'next_attempt_at',
            'last_error',
            'locked_at',
            'locked_by',
            'processed_at',
            'failed_at',
        ];

        foreach ($arrayKeyFields as $field) {
            $this->assertStringContainsString(
                "'{$field}'",
                $content,
                "OutboxEventProcessor must reference field '{$field}'."
            );
        }

        // max_attempts is read as an object property, not an array key
        $this->assertStringContainsString(
            'max_attempts',
            $content,
            'OutboxEventProcessor must read the max_attempts field to determine final failure.'
        );
    }

    // ── 9. Stale lock recovery ────────────────────────────────────────────────

    public function test_outbox_processor_has_stale_lock_recovery(): void
    {
        $content = $this->read(self::PROCESSOR);

        $this->assertMatchesRegularExpression(
            '/locked_at.*INTERVAL/s',
            $content,
            'OutboxEventProcessor must implement stale lock recovery via a timestamp interval check.'
        );
    }

    // ── 10. Exponential backoff ───────────────────────────────────────────────

    public function test_outbox_processor_implements_backoff_via_next_attempt_at(): void
    {
        $content = $this->read(self::PROCESSOR);

        $this->assertStringContainsString(
            'next_attempt_at',
            $content,
            'OutboxEventProcessor must implement retry backoff via next_attempt_at.'
        );

        $this->assertMatchesRegularExpression(
            '/BACKOFF_SECONDS|addSeconds/',
            $content,
            'OutboxEventProcessor must compute backoff delay before the next attempt.'
        );
    }

    // ── 11. No real notifications in Outbox module ────────────────────────────

    public function test_outbox_module_does_not_send_real_notifications(): void
    {
        $sharedModule = self::OUTBOX_MODULE;
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sharedModule, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files, 'Shared module must contain PHP files.');

        $forbidden = ['Mail::', 'Notification::', 'Sms::', 'WhatsApp', 'Http::post', 'Http::put', 'curl_exec'];

        foreach ($files as $filePath) {
            $content = (string) file_get_contents($filePath);

            foreach ($forbidden as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern,
                    $content,
                    basename($filePath)." must not call '{$pattern}' — real providers belong in Phase 9."
                );
            }
        }
    }

    // ── 12. RecordOutboxEventAction not called from Controllers ──────────────

    public function test_record_outbox_action_is_not_called_from_controllers(): void
    {
        $modulesDir = self::HTTP_CONTROLLERS;

        if (! is_dir($modulesDir)) {
            $this->fail("Modules directory not found: {$modulesDir}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulesDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (! str_contains($file->getPathname(), 'Controller')) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            $this->assertStringNotContainsString(
                'RecordOutboxEventAction',
                $content,
                basename($file->getPathname()).' must not call RecordOutboxEventAction directly — outbox recording belongs inside Actions.'
            );
        }

        $this->assertTrue(true, 'No Controller calls RecordOutboxEventAction directly.');
    }

    // ── 13. Dispatcher has exactly 5 handler methods ─────────────────────────

    public function test_dispatcher_has_exactly_five_handler_methods(): void
    {
        $content = $this->read(self::DISPATCHER);

        $count = preg_match_all('/private function handle\w+\(OutboxEvent/', $content, $matches);

        $this->assertSame(
            count(self::ALLOWED_EVENT_TYPES),
            $count,
            sprintf(
                'OutboxEventDispatcher must have exactly %d handler methods (one per allowed event type). Found %d.',
                count(self::ALLOWED_EVENT_TYPES),
                $count
            )
        );
    }

    // ── 14. Forbidden types not in dispatcher ─────────────────────────────────

    public function test_forbidden_event_types_are_not_in_dispatcher(): void
    {
        $content = $this->read(self::DISPATCHER);

        foreach (self::FORBIDDEN_OUTBOX_TYPES as $type) {
            $this->assertStringNotContainsString(
                "'{$type}'",
                $content,
                "OutboxEventDispatcher must not handle forbidden event type '{$type}' in Phase 8."
            );
        }
    }
}
