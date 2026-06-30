<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ── forgot-password ──────────────────────────────────────────────────────

    public function test_forgot_password_responds_200_for_existing_email(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'player@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'player@example.com'])
            ->assertOk()
            ->assertJson(['message' => 'Si el correo existe, enviaremos instrucciones para restablecer la contraseña.']);
    }

    public function test_forgot_password_responds_200_for_non_existing_email(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJson(['message' => 'Si el correo existe, enviaremos instrucciones para restablecer la contraseña.']);
    }

    public function test_forgot_password_same_body_for_existing_and_non_existing(): void
    {
        Notification::fake();
        User::factory()->unverified()->create(['email' => 'real@example.com']);

        $existing = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'real@example.com'])->json();
        $missing = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])->json();

        $this->assertSame($existing, $missing);
    }

    public function test_forgot_password_sends_notification_when_user_exists(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_does_not_send_notification_when_user_not_found(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_forgot_password_validates_email_format(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_forgot_password_requires_email(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_forgot_password_normalizes_email_before_lookup(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'player@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => '  PLAYER@EXAMPLE.COM  '])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        Notification::fake();
        $ip = '10.0.0.1';
        $email = 'throttle-fp@example.com';

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v1/auth/forgot-password', ['email' => $email])
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/forgot-password', ['email' => $email])
            ->assertTooManyRequests()
            ->assertJson(['error' => 'too_many_requests']);
    }

    public function test_forgot_password_notification_contains_token_in_url(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_contains((string) $url, 'token=') && str_contains((string) $url, 'email=');
        });
    }

    // ── reset-password ───────────────────────────────────────────────────────

    public function test_reset_password_with_valid_token_updates_password(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertOk()->assertJson(['message' => 'Contraseña actualizada correctamente.']);

        $user->refresh();
        $this->assertTrue(Hash::check('newSecurePass1', (string) $user->password));
    }

    public function test_reset_password_with_invalid_token_returns_422(): void
    {
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => 'completely-wrong-token',
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'password_reset_invalid');
    }

    public function test_reset_password_with_expired_token_returns_422(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = Password::broker()->createToken($user);

        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(65)]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'password_reset_invalid');
    }

    public function test_reset_password_requires_password_confirmation(): void
    {
        $user = User::factory()->unverified()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'different-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_reset_password_enforces_minimum_length(): void
    {
        $user = User::factory()->unverified()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_reset_password_revokes_all_sanctum_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $user->createToken('session-a', ['player:access']);
        $user->createToken('session-b', ['player:access']);
        $this->assertSame(2, PersonalAccessToken::where('tokenable_id', $user->id)->count());

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertOk();

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $user->id)->count());
    }

    public function test_reset_password_sets_email_verified_at_when_null(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->assertNull($user->email_verified_at);

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertOk();

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_reset_password_keeps_email_verified_at_when_already_set(): void
    {
        Notification::fake();
        $verifiedAt = now()->subDay()->startOfSecond();
        $user = User::factory()->create(['email_verified_at' => $verifiedAt]);

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertOk();

        $user->refresh();
        $this->assertEquals($verifiedAt, $user->email_verified_at);
    }

    public function test_reset_password_allows_social_only_user_and_creates_local_credential(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'password' => null,
            'email_verified_at' => now(),
        ]);
        UserSocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-id-123',
            'provider_email' => $user->email,
            'provider_email_verified_at' => now(),
        ]);

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('newSecurePass1', (string) $user->password));
        $this->assertSame(1, $user->socialAccounts()->count());
    }

    public function test_reset_password_allows_non_activated_invited_user(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['password' => null]);

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('newSecurePass1', (string) $user->password));
    }

    public function test_reset_password_allows_login_with_new_password(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'newSecurePass1',
        ])->assertOk();
    }

    public function test_reset_password_rejects_login_with_old_password(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create([
            'password' => Hash::make('oldPassword123'),
        ]);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'oldPassword123',
        ])->assertUnprocessable();
    }

    public function test_reset_password_does_not_change_role(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertOk();

        $user->refresh();
        $this->assertSame('player', $user->role->value);
    }

    public function test_reset_password_does_not_touch_social_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        UserSocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'facebook',
            'provider_user_id' => 'fb-id-456',
            'provider_email' => $user->email,
            'provider_email_verified_at' => null,
        ]);

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertOk();

        $this->assertSame(1, UserSocialAccount::where('user_id', $user->id)->count());
    }

    public function test_reset_password_does_not_create_user_for_unknown_email(): void
    {
        $countBefore = User::count();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'ghost@example.com',
            'token' => 'sometoken',
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'password_reset_invalid');

        $this->assertSame($countBefore, User::count());
    }

    public function test_responses_do_not_expose_token(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $plainToken = Password::broker()->createToken($user);

        $forgotBody = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $user->email,
        ])->getContent();

        $resetBody = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $plainToken,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->getContent();

        $this->assertStringNotContainsString($plainToken, (string) $forgotBody);
        $this->assertStringNotContainsString($plainToken, (string) $resetBody);
    }

    public function test_reset_password_with_mismatched_email_returns_422(): void
    {
        Notification::fake();
        $userA = User::factory()->unverified()->create();
        $userB = User::factory()->unverified()->create();

        $token = Password::broker()->createToken($userA);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $userB->email,
            'token' => $token,
            'password' => 'newSecurePass1',
            'password_confirmation' => 'newSecurePass1',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'password_reset_invalid');
    }

    public function test_reset_password_is_rate_limited(): void
    {
        $ip = '10.0.1.1';

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v1/auth/reset-password', [
                    'email' => 'x@example.com',
                    'token' => 'invalid',
                    'password' => 'newSecurePass1',
                    'password_confirmation' => 'newSecurePass1',
                ]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/reset-password', [
                'email' => 'x@example.com',
                'token' => 'invalid',
                'password' => 'newSecurePass1',
                'password_confirmation' => 'newSecurePass1',
            ])->assertTooManyRequests()
            ->assertJson(['error' => 'too_many_requests']);
    }
}
