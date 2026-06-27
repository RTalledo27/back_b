<?php

declare(strict_types=1);

namespace Tests\Integration\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Structural guards for Phase 6.3 (WinnerPayout).
 * Grep-based — no DB or Laravel boot needed.
 *
 * Key invariants:
 *  1. Only ProcessWinnerPayoutAction writes to winner_payouts (WinnerPayout::create)
 *  2. ProcessWinnerPayoutAction does not use IdempotentCommandExecutor or idempotency_keys
 *  3. ProcessWinnerPayoutAction does not catch UniqueConstraintViolationException
 *  4. ProcessWinnerPayoutAction does not expose disk/path/sha256 in GameEvent payload
 *  5. ShowWinnerPayoutController does not expose disk/path/sha256
 *  6. No player controller references ProcessWinnerPayoutAction
 *  7. WinnerPayoutResource does not expose sensitive fields
 *  8. RepeatNumberBingo module does not import Commerce classes (architecture boundary)
 */
final class Phase63PayoutArchitectureTest extends TestCase
{
    private const ACTION_ROOT = __DIR__.'/../../../app/Modules/Commerce/Application/Actions';

    private const CONTROLLER_ROOT = __DIR__.'/../../../app/Modules/Commerce/Presentation/Http/Controllers';

    private const RESOURCE_FILE = __DIR__.'/../../../app/Modules/Commerce/Presentation/Http/Resources/Admin/WinnerPayoutResource.php';

    private const PAYOUT_ACTION = self::ACTION_ROOT.'/ProcessWinnerPayoutAction.php';

    private const SHOW_CONTROLLER = self::CONTROLLER_ROOT.'/Admin/ShowWinnerPayoutController.php';

    private const BINGO_APP_ROOT = __DIR__.'/../../../app/Modules/RepeatNumberBingo';

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

    // ── 1. Only ProcessWinnerPayoutAction creates WinnerPayout ───────────────

    public function test_only_process_winner_payout_action_creates_winner_payout(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(self::ACTION_ROOT) as $file) {
            if (basename($file) === 'ProcessWinnerPayoutAction.php') {
                continue;
            }
            $content = file_get_contents($file) ?: '';
            if (str_contains($content, 'WinnerPayout::create')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Only ProcessWinnerPayoutAction may call WinnerPayout::create.',
        );
    }

    // ── 2. No IdempotentCommandExecutor or idempotency_keys table ────────────

    public function test_payout_action_does_not_use_idempotent_command_executor(): void
    {
        $content = file_get_contents(self::PAYOUT_ACTION) ?: '';

        $this->assertFalse(
            str_contains($content, 'IdempotentCommandExecutor'),
            'ProcessWinnerPayoutAction must not use IdempotentCommandExecutor.',
        );

        $this->assertFalse(
            str_contains($content, 'idempotency_keys'),
            'ProcessWinnerPayoutAction must not reference the idempotency_keys table.',
        );
    }

    // ── 3. No UniqueConstraintViolationException caught ───────────────────────

    public function test_payout_action_does_not_catch_unique_constraint_violation(): void
    {
        $content = file_get_contents(self::PAYOUT_ACTION) ?: '';

        $this->assertFalse(
            str_contains($content, 'UniqueConstraintViolationException'),
            'ProcessWinnerPayoutAction must not catch UniqueConstraintViolationException.',
        );
    }

    // ── 4. No sensitive fields in GameEvent payload ───────────────────────────

    public function test_payout_action_does_not_write_sensitive_fields_to_game_event(): void
    {
        $content = file_get_contents(self::PAYOUT_ACTION) ?: '';

        $forbidden = ['disk', 'path', 'sha256', 'idempotency_key_hash', 'request_fingerprint'];

        $payloadBlock = '';
        if (preg_match('/GameEvent::create\(\[(.*?)\]\)/s', $content, $m)) {
            $payloadBlock = $m[1];
        }

        foreach ($forbidden as $field) {
            $this->assertFalse(
                str_contains($payloadBlock, "'$field'") || str_contains($payloadBlock, "\"$field\""),
                "GameEvent payload must not include '{$field}'.",
            );
        }
    }

    // ── 5. ShowWinnerPayoutController does not expose sensitive fields ─────────

    public function test_show_controller_does_not_expose_sensitive_fields(): void
    {
        $content = file_get_contents(self::SHOW_CONTROLLER) ?: '';

        $forbidden = ['disk', 'path', 'sha256', 'idempotency_key_hash', 'request_fingerprint'];

        foreach ($forbidden as $field) {
            // We use WinnerPayoutResource which guards these; the controller itself
            // should not be passing them anywhere either.
            $this->assertFalse(
                str_contains($content, "'{$field}'") && str_contains($content, 'response'),
                "ShowWinnerPayoutController must not expose '{$field}' directly.",
            );
        }
    }

    // ── 6. No player controller references ProcessWinnerPayoutAction ──────────

    public function test_player_controllers_do_not_reference_process_winner_payout_action(): void
    {
        $playerDir = self::CONTROLLER_ROOT.'/Player';
        $offenders = [];

        foreach ($this->phpFilesUnder($playerDir) as $file) {
            $content = file_get_contents($file) ?: '';
            if (str_contains($content, 'ProcessWinnerPayoutAction')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Player-facing controllers must not reference ProcessWinnerPayoutAction.',
        );
    }

    // ── 7. WinnerPayoutResource does not expose sensitive fields ──────────────

    public function test_winner_payout_resource_does_not_expose_sensitive_fields(): void
    {
        $content = file_get_contents(self::RESOURCE_FILE) ?: '';

        $sensitiveKeys = [
            'idempotency_key_hash',
            'request_fingerprint',
            "'disk'",
            "'path'",
            "'sha256'",
        ];

        foreach ($sensitiveKeys as $key) {
            $this->assertFalse(
                str_contains($content, $key),
                "WinnerPayoutResource must not reference '{$key}'.",
            );
        }
    }

    // ── 8. RepeatNumberBingo module does not import Commerce classes ───────────

    public function test_repeat_number_bingo_module_does_not_import_commerce_classes(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(self::BINGO_APP_ROOT) as $file) {
            $content = file_get_contents($file) ?: '';
            if (str_contains($content, 'App\\Modules\\Commerce\\')) {
                $offenders[] = str_replace(self::BINGO_APP_ROOT.DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'RepeatNumberBingo module must not import Commerce classes (architecture boundary violated).',
        );
    }
}
