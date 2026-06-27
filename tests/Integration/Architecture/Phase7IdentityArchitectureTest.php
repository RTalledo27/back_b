<?php

declare(strict_types=1);

namespace Tests\Integration\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Structural guards for Phase 7.2 (Password reset).
 * Grep-based — no DB or Laravel boot needed.
 *
 * Key invariants defended here:
 *  1. Controllers do NOT own DB::transaction — the action does.
 *  2. ResetPasswordAction deletes all Sanctum tokens inside the transaction.
 *  3. ResetPasswordAction handles email_verified_at inside the transaction.
 *  4. Neither controller queries password_reset_tokens directly — the broker owns it.
 *  5. ForgotPasswordController does not branch on broker status (anti-enumeration).
 *  6. Token plain text is never written to password_reset_tokens by our code (broker hashes).
 */
final class Phase7IdentityArchitectureTest extends TestCase
{
    private const AUTH_CONTROLLERS = __DIR__.'/../../../app/Http/Controllers/Auth';

    private const AUTH_ACTIONS = __DIR__.'/../../../app/Actions/Auth';

    private const FORGOT_CONTROLLER = self::AUTH_CONTROLLERS.'/ForgotPasswordController.php';

    private const RESET_CONTROLLER = self::AUTH_CONTROLLERS.'/ResetPasswordController.php';

    private const FORGOT_ACTION = self::AUTH_ACTIONS.'/SendPasswordResetLinkAction.php';

    private const RESET_ACTION = self::AUTH_ACTIONS.'/ResetPasswordAction.php';

    // ── 1. Controllers do NOT own DB::transaction ────────────────────────────

    public function test_forgot_password_controller_does_not_call_db_transaction(): void
    {
        $content = file_get_contents(self::FORGOT_CONTROLLER) ?: '';

        $this->assertFalse(
            str_contains($content, 'DB::transaction'),
            'ForgotPasswordController must not call DB::transaction — the action owns persistence.',
        );
    }

    public function test_reset_password_controller_does_not_call_db_transaction(): void
    {
        $content = file_get_contents(self::RESET_CONTROLLER) ?: '';

        $this->assertFalse(
            str_contains($content, 'DB::transaction'),
            'ResetPasswordController must not call DB::transaction — the action owns persistence.',
        );
    }

    public function test_reset_password_action_calls_db_transaction(): void
    {
        $content = file_get_contents(self::RESET_ACTION) ?: '';

        $this->assertTrue(
            str_contains($content, 'DB::transaction'),
            'ResetPasswordAction must wrap password change + token revocation in DB::transaction.',
        );
    }

    // ── 2. Sanctum token revocation lives inside the transaction ─────────────

    public function test_reset_password_action_deletes_sanctum_tokens(): void
    {
        $content = file_get_contents(self::RESET_ACTION) ?: '';

        $this->assertTrue(
            str_contains($content, 'tokens()->delete()'),
            'ResetPasswordAction must call tokens()->delete() to revoke all Sanctum tokens.',
        );
    }

    // ── 3. email_verified_at is handled inside ResetPasswordAction ───────────

    public function test_reset_password_action_handles_email_verified_at(): void
    {
        $content = file_get_contents(self::RESET_ACTION) ?: '';

        $this->assertTrue(
            str_contains($content, 'email_verified_at'),
            'ResetPasswordAction must set email_verified_at when null (reset proves mailbox control).',
        );
    }

    public function test_reset_password_controller_does_not_touch_email_verified_at(): void
    {
        $content = file_get_contents(self::RESET_CONTROLLER) ?: '';

        $this->assertFalse(
            str_contains($content, 'email_verified_at'),
            'ResetPasswordController must not manage email_verified_at — the action owns that.',
        );
    }

    // ── 4. Controllers do not query password_reset_tokens directly ───────────

    public function test_forgot_password_controller_does_not_query_token_table(): void
    {
        $content = file_get_contents(self::FORGOT_CONTROLLER) ?: '';

        $this->assertFalse(
            str_contains($content, 'password_reset_tokens'),
            'ForgotPasswordController must not query password_reset_tokens — the broker owns it.',
        );
    }

    public function test_reset_password_controller_does_not_query_token_table(): void
    {
        $content = file_get_contents(self::RESET_CONTROLLER) ?: '';

        $this->assertFalse(
            str_contains($content, 'password_reset_tokens'),
            'ResetPasswordController must not query password_reset_tokens — the broker owns it.',
        );
    }

    public function test_reset_password_action_does_not_query_token_table_directly(): void
    {
        $content = file_get_contents(self::RESET_ACTION) ?: '';

        $this->assertFalse(
            str_contains($content, 'password_reset_tokens'),
            'ResetPasswordAction must not query password_reset_tokens — Password::reset() owns it.',
        );
    }

    // ── 5. ForgotPasswordController does not branch on broker status ─────────

    public function test_forgot_password_controller_does_not_branch_on_broker_status(): void
    {
        $content = file_get_contents(self::FORGOT_CONTROLLER) ?: '';

        $this->assertFalse(
            str_contains($content, 'RESET_LINK_SENT'),
            'ForgotPasswordController must not branch on RESET_LINK_SENT — '
            .'always returns the same 200 to prevent user enumeration.',
        );

        $this->assertFalse(
            str_contains($content, 'INVALID_USER'),
            'ForgotPasswordController must not branch on INVALID_USER — '
            .'always returns the same 200 to prevent user enumeration.',
        );
    }

    // ── 6. Our code does not insert plain tokens into password_reset_tokens ──

    public function test_neither_action_inserts_into_password_reset_tokens_directly(): void
    {
        $forgotContent = file_get_contents(self::FORGOT_ACTION) ?: '';
        $resetContent = file_get_contents(self::RESET_ACTION) ?: '';

        foreach (['table(\'password_reset_tokens\')', 'DB::insert'] as $needle) {
            $this->assertFalse(
                str_contains($forgotContent, $needle),
                "SendPasswordResetLinkAction must not write to password_reset_tokens directly ({$needle}).",
            );
            $this->assertFalse(
                str_contains($resetContent, $needle),
                "ResetPasswordAction must not write to password_reset_tokens directly ({$needle}).",
            );
        }
    }

    // ── 7. Actions use Password facade, not Mail directly ─────────────────────

    public function test_forgot_password_action_uses_password_broker_not_mail(): void
    {
        $content = file_get_contents(self::FORGOT_ACTION) ?: '';

        $this->assertTrue(
            str_contains($content, 'Password::sendResetLink'),
            'SendPasswordResetLinkAction must use Password::sendResetLink via the broker.',
        );

        $this->assertFalse(
            str_contains($content, 'Mail::'),
            'SendPasswordResetLinkAction must not call Mail:: directly — the broker handles notification.',
        );
    }

    public function test_reset_password_action_uses_password_broker_not_db_raw(): void
    {
        $content = file_get_contents(self::RESET_ACTION) ?: '';

        $this->assertTrue(
            str_contains($content, 'Password::reset'),
            'ResetPasswordAction must use Password::reset via the broker.',
        );

        $this->assertFalse(
            str_contains($content, 'DB::statement'),
            'ResetPasswordAction must not use raw DB::statement.',
        );
    }
}
