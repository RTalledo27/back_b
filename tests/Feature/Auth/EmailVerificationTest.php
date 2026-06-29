<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\DTOs\Auth\SocialUserData;
use App\Models\OauthAttempt;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Services\Auth\SocialProviderAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSocialProviderAdapter;
use Tests\TestCase;

final class EmailVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private FakeSocialProviderAdapter $fakeAdapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAdapter = new FakeSocialProviderAdapter;
        $this->app->instance(SocialProviderAdapter::class, $this->fakeAdapter);

        config(['services.social_auth.frontend_url' => 'http://localhost:3000']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function signedVerifyPath(User $user, ?\DateTimeInterface $expiry = null): string
    {
        $expiry ??= now()->addHour();
        $url = URL::temporarySignedRoute(
            'auth.email.verify',
            $expiry,
            ['id' => $user->id, 'hash' => sha1((string) $user->email)],
        );
        $parsed = parse_url($url);

        return ($parsed['path'] ?? '').'?'.($parsed['query'] ?? '');
    }

    private function makeAttempt(string $provider = 'google', int $ttlSeconds = 600): array
    {
        $plainState = Str::random(64);
        $attempt = OauthAttempt::create([
            'provider' => $provider,
            'state_hash' => hash('sha256', $plainState),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        return [$attempt, $plainState];
    }

    private function callbackUrl(string $provider, string $plainState): string
    {
        return "/api/v1/auth/social/{$provider}/callback?state={$plainState}&code=fake-code";
    }

    // ── Resend verification ───────────────────────────────────────────────────

    public function test_resend_sends_notification_for_unverified_user(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'Si tu correo aún no está verificado, enviaremos un enlace de verificación.');

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_resend_returns_200_and_skips_notification_for_verified_user(): void
    {
        Notification::fake();
        $user = User::factory()->create(); // default: email_verified_at = now()
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_resend_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertUnauthorized();
    }

    public function test_resend_is_rate_limited_after_three_requests(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/email/verification-notification')->assertOk();
        }

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertTooManyRequests()
            ->assertJson(['error' => 'too_many_requests']);
    }

    // ── Verify ────────────────────────────────────────────────────────────────

    public function test_verify_with_valid_signed_url_returns_200_and_marks_verified(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson($this->signedVerifyPath($user))
            ->assertOk()
            ->assertJsonFragment(['email_verified' => true, 'message' => 'Correo verificado correctamente.']);

        $this->assertNotNull($user->fresh()?->email_verified_at);
    }

    public function test_verify_is_idempotent_when_already_verified(): void
    {
        $user = User::factory()->create();
        $verifiedAt = $user->email_verified_at;
        Sanctum::actingAs($user);

        $this->postJson($this->signedVerifyPath($user))
            ->assertOk()
            ->assertJsonFragment(['email_verified' => true]);

        // email_verified_at must not be updated
        $this->assertEquals($verifiedAt, $user->fresh()?->email_verified_at);
    }

    public function test_verify_with_id_mismatch_returns_422(): void
    {
        $userA = User::factory()->unverified()->create();
        $userB = User::factory()->unverified()->create();

        // Sign URL for user B, but authenticate as user A
        $signedPath = $this->signedVerifyPath($userB);
        Sanctum::actingAs($userA);

        $this->postJson($signedPath)
            ->assertStatus(422)
            ->assertJsonPath('code', 'email_verification_invalid');
    }

    public function test_verify_with_hash_mismatch_returns_422(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $wrongHash = sha1('not-the-real-email-'.$user->email);
        $url = URL::temporarySignedRoute(
            'auth.email.verify',
            now()->addHour(),
            ['id' => $user->id, 'hash' => $wrongHash],
        );
        $parsed = parse_url($url);
        $requestPath = ($parsed['path'] ?? '').'?'.($parsed['query'] ?? '');

        $this->postJson($requestPath)
            ->assertStatus(422)
            ->assertJsonPath('code', 'email_verification_invalid');
    }

    public function test_verify_with_expired_url_returns_422(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $expiredPath = $this->signedVerifyPath($user, now()->subSecond());

        $this->postJson($expiredPath)
            ->assertStatus(422)
            ->assertJsonPath('code', 'email_verification_invalid');
    }

    public function test_verify_with_tampered_signature_returns_422(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $hash = sha1((string) $user->email);
        $expires = now()->addHour()->timestamp;
        $path = "/api/v1/auth/email/verify/{$user->id}/{$hash}?expires={$expires}&signature=deadbeef00000000";

        $this->postJson($path)
            ->assertStatus(422)
            ->assertJsonPath('code', 'email_verification_invalid');
    }

    public function test_verify_requires_authentication(): void
    {
        $user = User::factory()->unverified()->create();
        $path = $this->signedVerifyPath($user);

        $this->postJson($path)
            ->assertUnauthorized();
    }

    public function test_verify_updates_me_endpoint_email_verified_flag(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('session', ['player:access'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email_verified', false);

        $this->withToken($token)
            ->postJson($this->signedVerifyPath($user))
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email_verified', true);
    }

    public function test_verify_is_rate_limited(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $path = $this->signedVerifyPath($user);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson($path)->assertOk();
        }

        $this->postJson($path)
            ->assertTooManyRequests()
            ->assertJson(['error' => 'too_many_requests']);
    }

    // ── Behavioral ────────────────────────────────────────────────────────────

    public function test_local_registration_creates_unverified_user(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Player',
            'email' => 'newplayer@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated();

        $response->assertJsonPath('data.user.email_verified', false);

        $user = User::where('email', 'newplayer@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
    }

    public function test_google_oauth_creates_user_with_email_verified(): void
    {
        $this->fakeAdapter->configureUser(new SocialUserData(
            provider: 'google',
            providerId: 'g-email-verify-test',
            email: 'googleverified@example.com',
            emailVerified: true,
            name: 'Google User',
        ));
        [, $plainState] = $this->makeAttempt('google');

        $this->get($this->callbackUrl('google', $plainState))->assertRedirect();

        $user = User::where('email', 'googleverified@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_facebook_oauth_rejects_unverified_email_and_creates_no_user(): void
    {
        $this->fakeAdapter->configureUser(new SocialUserData(
            provider: 'facebook',
            providerId: 'fb-email-verify-test',
            email: 'fbunverified@example.com',
            emailVerified: false,
            name: 'Facebook User',
        ));
        [, $plainState] = $this->makeAttempt('facebook');

        $response = $this->get($this->callbackUrl('facebook', $plainState));
        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame('verified_email_required', $params['error'] ?? null);

        $this->assertDatabaseMissing('users', ['email' => 'fbunverified@example.com']);
    }
}
