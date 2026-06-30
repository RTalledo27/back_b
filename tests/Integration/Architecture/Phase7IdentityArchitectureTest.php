<?php

declare(strict_types=1);

namespace Tests\Integration\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Structural guards for Phase 7.2 (Password reset), 7.3 (Email verification) and 7.4 (Hardening).
 * Grep-based — no DB or Laravel boot needed.
 *
 * Key invariants defended here:
 *  1. Controllers do NOT own DB::transaction — the action does.
 *  2. ResetPasswordAction deletes all Sanctum tokens inside the transaction.
 *  3. ResetPasswordAction handles email_verified_at inside the transaction.
 *  4. Neither controller queries password_reset_tokens directly — the broker owns it.
 *  5. ForgotPasswordController does not branch on broker status (anti-enumeration).
 *  6. Token plain text is never written to password_reset_tokens by our code (broker hashes).
 *  7. VerifyEmailAction uses hash_equals — no timing-attack-vulnerable comparison.
 *  8. VerifyEmailAction checks id match before hash — fails fast without leaking timing.
 *  9. VerifyEmailController does NOT own the id/hash validation — the action does.
 * 10. SendEmailVerificationNotificationController returns uniform 200 (no branching on verified status).
 * 11. EnsureEmailIsVerified returns 403 with code=email_not_verified, never redirects.
 * 12. verified is registered only as an alias, never as a global middleware.
 * 13. No 2FA/SMS/phone/magic-link files exist in auth directories.
 * 14. AuthUserResource contains no Eloquent queries.
 * 15. verified is applied only to the two commerce write endpoints.
 */
final class Phase7IdentityArchitectureTest extends TestCase
{
    private const AUTH_CONTROLLERS = __DIR__.'/../../../app/Http/Controllers/Auth';

    private const AUTH_ACTIONS = __DIR__.'/../../../app/Actions/Auth';

    private const FORGOT_CONTROLLER = self::AUTH_CONTROLLERS.'/ForgotPasswordController.php';

    private const RESET_CONTROLLER = self::AUTH_CONTROLLERS.'/ResetPasswordController.php';

    private const FORGOT_ACTION = self::AUTH_ACTIONS.'/SendPasswordResetLinkAction.php';

    private const RESET_ACTION = self::AUTH_ACTIONS.'/ResetPasswordAction.php';

    private const SEND_VERIFICATION_CONTROLLER = self::AUTH_CONTROLLERS.'/SendEmailVerificationNotificationController.php';

    private const VERIFY_EMAIL_CONTROLLER = self::AUTH_CONTROLLERS.'/VerifyEmailController.php';

    private const VERIFY_EMAIL_ACTION = self::AUTH_ACTIONS.'/VerifyEmailAction.php';

    private const ENSURE_VERIFIED_MIDDLEWARE = __DIR__.'/../../../app/Http/Middleware/EnsureEmailIsVerified.php';

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

    // ── Phase 7.3 — Email verification ────────────────────────────────────────

    public function test_verify_email_action_uses_hash_equals(): void
    {
        $content = file_get_contents(self::VERIFY_EMAIL_ACTION) ?: '';

        $this->assertTrue(
            str_contains($content, 'hash_equals'),
            'VerifyEmailAction must use hash_equals() for constant-time hash comparison.',
        );
    }

    public function test_verify_email_action_checks_id_and_hash(): void
    {
        $content = file_get_contents(self::VERIFY_EMAIL_ACTION) ?: '';

        $this->assertTrue(
            str_contains($content, 'getKey()'),
            'VerifyEmailAction must compare route id against $user->getKey().',
        );
        $this->assertTrue(
            str_contains($content, 'getEmailForVerification()'),
            'VerifyEmailAction must use getEmailForVerification() to get the canonical email for hashing.',
        );
    }

    public function test_verify_email_action_does_not_change_tokens_or_role(): void
    {
        $content = file_get_contents(self::VERIFY_EMAIL_ACTION) ?: '';

        $this->assertFalse(
            str_contains($content, 'tokens()'),
            'VerifyEmailAction must not touch Sanctum tokens.',
        );
        $this->assertFalse(
            str_contains($content, 'role'),
            'VerifyEmailAction must not touch user role.',
        );
    }

    public function test_verify_email_controller_does_not_validate_id_or_hash(): void
    {
        $content = file_get_contents(self::VERIFY_EMAIL_CONTROLLER) ?: '';

        $this->assertFalse(
            str_contains($content, 'hash_equals'),
            'VerifyEmailController must not perform hash comparison — the action owns it.',
        );
        $this->assertFalse(
            str_contains($content, 'getKey()'),
            'VerifyEmailController must not compare user id — the action owns it.',
        );
    }

    public function test_send_verification_controller_returns_200_without_branching_on_verified_status(): void
    {
        $content = file_get_contents(self::SEND_VERIFICATION_CONTROLLER) ?: '';

        $this->assertFalse(
            str_contains($content, 'hasVerifiedEmail'),
            'SendEmailVerificationNotificationController must not branch on hasVerifiedEmail() — '
            .'the action decides; the controller always returns 200.',
        );
    }

    public function test_ensure_email_is_verified_returns_json_not_redirect(): void
    {
        $content = file_get_contents(self::ENSURE_VERIFIED_MIDDLEWARE) ?: '';

        $this->assertTrue(
            str_contains($content, 'response()->json'),
            'EnsureEmailIsVerified must return a JSON response, not a redirect.',
        );
        $this->assertFalse(
            str_contains($content, 'redirect('),
            'EnsureEmailIsVerified must not redirect — it must return 403 JSON.',
        );
        $this->assertTrue(
            str_contains($content, 'email_not_verified'),
            "EnsureEmailIsVerified must include code 'email_not_verified' in the response.",
        );
    }

    public function test_verify_email_notification_uses_temporary_signed_route(): void
    {
        $notificationPath = __DIR__.'/../../../app/Notifications/Auth/VerifyEmailNotification.php';
        $content = file_get_contents($notificationPath) ?: '';

        $this->assertTrue(
            str_contains($content, 'temporarySignedRoute'),
            'VerifyEmailNotification must use URL::temporarySignedRoute() for the verification link.',
        );
        $this->assertFalse(
            str_contains($content, 'signedRoute('),
            'VerifyEmailNotification must use temporarySignedRoute (TTL), not signedRoute (no TTL).',
        );
    }

    // ── Phase 7.4 — Security hardening guards ────────────────────────────────

    public function test_verified_is_registered_only_as_alias_not_as_global_middleware(): void
    {
        $bootstrapPath = __DIR__.'/../../../bootstrap/app.php';
        $content = file_get_contents($bootstrapPath) ?: '';

        $this->assertFalse(
            (bool) preg_match('/->append\s*\(\s*[\'"]?verified/', $content),
            'verified must not be appended as a global middleware.',
        );
        $this->assertFalse(
            (bool) preg_match('/->prepend\s*\(\s*[\'"]?verified/', $content),
            'verified must not be prepended as a global middleware.',
        );
        $this->assertFalse(
            (bool) preg_match('/->api\s*\(\s*[\'"]?verified/', $content),
            'verified must not be injected into the api middleware group globally.',
        );
        $this->assertStringContainsString(
            "'verified' => EnsureEmailIsVerified::class",
            $content,
            'verified must be registered as a route alias in bootstrap/app.php.',
        );
    }

    public function test_no_2fa_sms_phone_or_magic_link_files_exist_in_auth(): void
    {
        $dirs = [
            __DIR__.'/../../../app/Http/Controllers/Auth',
            __DIR__.'/../../../app/Actions/Auth',
            __DIR__.'/../../../app/Notifications/Auth',
        ];
        $forbidden = ['2fa', 'twofactor', 'mfa', 'sms', 'magiclink', 'totp', 'phonelogin'];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir.'/*.php') ?: [] as $file) {
                $filename = strtolower(basename($file));
                foreach ($forbidden as $keyword) {
                    $this->assertStringNotContainsString(
                        $keyword,
                        $filename,
                        "File {$file} suggests unauthorized 2FA/SMS/phone/magic-link implementation.",
                    );
                }
            }
        }
    }

    public function test_auth_user_resource_contains_no_eloquent_queries(): void
    {
        $resourcePath = __DIR__.'/../../../app/Http/Resources/Auth/AuthUserResource.php';
        $content = file_get_contents($resourcePath) ?: '';

        foreach (['::find(', '::where(', '::query(', 'DB::', '->with('] as $needle) {
            $this->assertFalse(
                str_contains($content, $needle),
                "AuthUserResource must not contain Eloquent queries ({$needle}) — it must only read from the hydrated model.",
            );
        }
    }

    public function test_verified_middleware_applied_only_to_commerce_write_endpoints(): void
    {
        $routesPath = __DIR__.'/../../../routes/api.php';
        $content = file_get_contents($routesPath) ?: '';

        $this->assertSame(
            2,
            substr_count($content, "'verified'"),
            "The 'verified' middleware must appear in routes/api.php exactly twice — only for reservations and payment-evidence POST endpoints.",
        );
    }
}
