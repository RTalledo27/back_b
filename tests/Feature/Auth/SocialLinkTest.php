<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\DTOs\Auth\SocialUserData;
use App\Models\OauthAttempt;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\Auth\SocialProviderAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakeSocialProviderAdapter;
use Tests\TestCase;

final class SocialLinkTest extends TestCase
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

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeLinkAttempt(
        User $user,
        string $provider = 'google',
        ?string $plainState = null,
        int $ttlSeconds = 600,
    ): array {
        $plainState ??= Str::random(64);
        $attempt = OauthAttempt::create([
            'provider' => $provider,
            'purpose' => 'link',
            'initiated_by_user_id' => $user->id,
            'state_hash' => hash('sha256', $plainState),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        return [$attempt, $plainState];
    }

    private function makeSocialUser(
        string $provider = 'google',
        string $providerId = 'prov-link-123',
        ?string $email = 'social@example.com',
        bool $emailVerified = true,
        string $name = 'Social User',
    ): SocialUserData {
        return new SocialUserData($provider, $providerId, $email, $emailVerified, $name);
    }

    private function linkCallbackUrl(string $provider, string $plainState, string $code = 'fake-code'): string
    {
        return "/api/v1/auth/social/{$provider}/link/callback?state={$plainState}&code={$code}";
    }

    private function parseRedirectParams(string $redirectUrl): array
    {
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $params);

        return $params;
    }

    private function makeUserWithPassword(string $password = 'secret123'): User
    {
        return User::factory()->create(['password' => $password]);
    }

    private function makeUserWithoutPassword(): User
    {
        return User::factory()->create(['password' => null]);
    }

    private function makeSocialToken(User $user, string $provider = 'google'): string
    {
        return $user->createToken('social:'.$provider, ['social_reauth'])->plainTextToken;
    }

    private function makeExpiredSocialToken(User $user, string $provider = 'google', int $ageSeconds = 400): string
    {
        $token = $user->createToken('social:'.$provider, ['social_reauth']);
        $token->accessToken->forceFill(['created_at' => now()->subSeconds($ageSeconds)])->save();

        return $token->plainTextToken;
    }

    // ─── Social accounts listing ───────────────────────────────────────────────

    public function test_social_accounts_list_shows_linked_providers(): void
    {
        $user = $this->makeUserWithPassword();
        UserSocialAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_email' => 'user@google.com',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/social-accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider', 'google');
    }

    public function test_social_accounts_list_does_not_expose_provider_user_id(): void
    {
        $user = $this->makeUserWithPassword();
        UserSocialAccount::factory()->create(['user_id' => $user->id]);

        $body = $this->actingAs($user)
            ->getJson('/api/v1/auth/social-accounts')
            ->assertOk()
            ->json();

        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('provider_user_id', $json);
    }

    public function test_social_accounts_list_masks_provider_email(): void
    {
        $user = $this->makeUserWithPassword();
        UserSocialAccount::factory()->create([
            'user_id' => $user->id,
            'provider_email' => 'johndoe@gmail.com',
        ]);

        $masked = $this->actingAs($user)
            ->getJson('/api/v1/auth/social-accounts')
            ->assertOk()
            ->json('data.0.provider_email_masked');

        $this->assertStringStartsWith('jo', (string) $masked);
        $this->assertStringContainsString('***@gmail.com', (string) $masked);
        $this->assertStringNotContainsString('johndoe', (string) $masked);
    }

    public function test_social_accounts_list_shows_can_unlink_true_when_password_exists(): void
    {
        $user = $this->makeUserWithPassword();
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/social-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.can_unlink', true);
    }

    public function test_social_accounts_list_shows_can_unlink_true_when_two_social_accounts(): void
    {
        $user = $this->makeUserWithoutPassword();
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);
        UserSocialAccount::factory()->facebook()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/social-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.can_unlink', true)
            ->assertJsonPath('data.1.can_unlink', true);
    }

    public function test_social_accounts_list_shows_can_unlink_false_for_last_method(): void
    {
        $user = $this->makeUserWithoutPassword();
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/social-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.can_unlink', false);
    }

    public function test_social_accounts_list_shows_provider_email_verified_flag(): void
    {
        $user = $this->makeUserWithPassword();
        UserSocialAccount::factory()->create([
            'user_id' => $user->id,
            'provider_email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/social-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.provider_email_verified', true);
    }

    public function test_social_accounts_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/social-accounts')->assertUnauthorized();
    }

    public function test_social_accounts_list_does_not_expose_hashes_or_tokens(): void
    {
        $user = $this->makeUserWithPassword();
        UserSocialAccount::factory()->create(['user_id' => $user->id]);

        $body = $this->actingAs($user)
            ->getJson('/api/v1/auth/social-accounts')
            ->assertOk()
            ->json();

        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('state_hash', $json);
        $this->assertStringNotContainsString('exchange_code_hash', $json);
        $this->assertStringNotContainsString('access_token', $json);
    }

    // ─── Link redirect ────────────────────────────────────────────────────────

    public function test_link_redirect_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/social/google/link/redirect')->assertUnauthorized();
    }

    public function test_link_redirect_creates_attempt_with_purpose_link_and_user(): void
    {
        $user = $this->makeUserWithPassword();

        $this->actingAs($user)
            ->get('/api/v1/auth/social/google/link/redirect')
            ->assertRedirect();

        $this->assertDatabaseHas('oauth_attempts', [
            'provider' => 'google',
            'purpose' => 'link',
            'initiated_by_user_id' => $user->id,
            'consumed_at' => null,
        ]);
    }

    public function test_link_redirect_invalid_provider_returns_404(): void
    {
        $user = $this->makeUserWithPassword();

        $this->actingAs($user)
            ->get('/api/v1/auth/social/twitter/link/redirect')
            ->assertNotFound();
    }

    public function test_link_redirect_stores_only_hashed_state(): void
    {
        $user = $this->makeUserWithPassword();

        $response = $this->actingAs($user)
            ->get('/api/v1/auth/social/google/link/redirect');
        $response->assertRedirect();

        $redirectUrl = (string) $response->headers->get('Location');
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $q);
        $plainState = $q['state'] ?? '';

        $this->assertNotEmpty($plainState);
        $this->assertDatabaseMissing('oauth_attempts', ['state_hash' => $plainState]);
        $this->assertDatabaseHas('oauth_attempts', ['state_hash' => hash('sha256', $plainState)]);
    }

    // ─── Link callback — state & purpose validation ────────────────────────────

    public function test_link_callback_with_oauth_error_param_redirects_with_error(): void
    {
        $response = $this->get('/api/v1/auth/social/google/link/callback?error=access_denied');
        $response->assertRedirect();
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));
        $this->assertSame('oauth_error', $params['error'] ?? null);
    }

    public function test_link_callback_invalid_state_redirects_with_invalid_state(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser());
        $response = $this->get($this->linkCallbackUrl('google', Str::random(64)));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));
        $this->assertSame('invalid_state', $params['error'] ?? null);
    }

    public function test_link_callback_expired_state_redirects_with_expired_state(): void
    {
        $user = $this->makeUserWithPassword();
        $this->fakeAdapter->configureUser($this->makeSocialUser());
        [, $plainState] = $this->makeLinkAttempt($user, ttlSeconds: -1);

        $response = $this->get($this->linkCallbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));
        $this->assertSame('expired_state', $params['error'] ?? null);
    }

    public function test_link_callback_already_consumed_redirects_with_already_processed(): void
    {
        $user = $this->makeUserWithPassword();
        $this->fakeAdapter->configureUser($this->makeSocialUser());
        [, $plainState] = $this->makeLinkAttempt($user);

        $this->get($this->linkCallbackUrl('google', $plainState));

        $response = $this->get($this->linkCallbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));
        $this->assertSame('callback_already_processed', $params['error'] ?? null);
    }

    public function test_link_callback_cannot_use_login_purpose_attempt(): void
    {
        // A login-purpose OauthAttempt must NOT be usable as a link-callback state.
        $loginAttempt = OauthAttempt::create([
            'provider' => 'google',
            'purpose' => 'login',
            'state_hash' => hash('sha256', $plainState = Str::random(64)),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->fakeAdapter->configureUser($this->makeSocialUser());
        $response = $this->get($this->linkCallbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('invalid_state', $params['error'] ?? null);
        $this->assertNull($loginAttempt->fresh()->consumed_at, 'Login attempt must not be consumed');
    }

    public function test_link_callback_works_without_session_stateless(): void
    {
        $user = $this->makeUserWithPassword();

        // ① Redirect (creates link attempt with state_hash in DB).
        $redirectResponse = $this->actingAs($user)
            ->get('/api/v1/auth/social/google/link/redirect');
        parse_str((string) parse_url((string) $redirectResponse->headers->get('Location'), PHP_URL_QUERY), $q);
        $plainState = $q['state'] ?? '';
        $this->assertNotEmpty($plainState);

        // ② Clear session — callback must work from DB alone.
        $this->withSession([]);

        // ③ Callback with correct state succeeds without any session data.
        $this->fakeAdapter->configureUser($this->makeSocialUser());
        $callbackParams = $this->parseRedirectParams(
            (string) $this->get($this->linkCallbackUrl('google', $plainState))->headers->get('Location'),
        );
        $this->assertArrayHasKey('outcome', $callbackParams, 'Link callback must work without session');
        $this->assertSame('social_linked', $callbackParams['outcome']);

        // ④ Wrong state is always rejected.
        $wrongParams = $this->parseRedirectParams(
            (string) $this->get($this->linkCallbackUrl('google', Str::random(64)))->headers->get('Location'),
        );
        $this->assertSame('invalid_state', $wrongParams['error'] ?? null);
    }

    // ─── Link callback — identity resolution ──────────────────────────────────

    public function test_link_callback_free_identity_links_to_initiating_user(): void
    {
        $user = $this->makeUserWithPassword();
        $this->fakeAdapter->configureUser($this->makeSocialUser(providerId: 'g-new-777'));
        [, $plainState] = $this->makeLinkAttempt($user);

        $response = $this->get($this->linkCallbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('social_linked', $params['outcome'] ?? null);
        $this->assertSame('google', $params['provider'] ?? null);

        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'g-new-777',
        ]);
    }

    public function test_link_callback_does_not_create_another_user(): void
    {
        $user = $this->makeUserWithPassword();
        $this->fakeAdapter->configureUser($this->makeSocialUser());
        [, $plainState] = $this->makeLinkAttempt($user);

        $this->get($this->linkCallbackUrl('google', $plainState));

        $this->assertSame(1, User::query()->count());
    }

    public function test_link_callback_does_not_change_user_role(): void
    {
        $user = $this->makeUserWithPassword();
        $roleBeforeLink = $user->role->value;
        $this->fakeAdapter->configureUser($this->makeSocialUser());
        [, $plainState] = $this->makeLinkAttempt($user);

        $this->get($this->linkCallbackUrl('google', $plainState));

        $this->assertSame($roleBeforeLink, $user->fresh()->role->value);
    }

    public function test_link_callback_does_not_emit_sanctum_token_in_url(): void
    {
        $user = $this->makeUserWithPassword();
        $this->fakeAdapter->configureUser($this->makeSocialUser());
        [, $plainState] = $this->makeLinkAttempt($user);

        $response = $this->get($this->linkCallbackUrl('google', $plainState));
        $redirectUrl = (string) $response->headers->get('Location');

        $this->assertStringNotContainsString('|', $redirectUrl,
            'Sanctum token (contains "|") must never appear in the redirect URL.');
    }

    public function test_link_callback_already_linked_same_user_is_idempotent(): void
    {
        $user = $this->makeUserWithPassword();
        UserSocialAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'g-already-linked',
        ]);

        $this->fakeAdapter->configureUser($this->makeSocialUser(providerId: 'g-already-linked'));
        [, $plainState] = $this->makeLinkAttempt($user);

        $response = $this->get($this->linkCallbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('already_linked', $params['outcome'] ?? null);
        $this->assertSame(1, UserSocialAccount::query()->count());
    }

    public function test_link_callback_identity_linked_to_another_user_returns_conflict(): void
    {
        $otherUser = $this->makeUserWithPassword();
        UserSocialAccount::factory()->create([
            'user_id' => $otherUser->id,
            'provider' => 'google',
            'provider_user_id' => 'g-taken-by-other',
        ]);

        $linkingUser = $this->makeUserWithPassword();
        $this->fakeAdapter->configureUser($this->makeSocialUser(providerId: 'g-taken-by-other'));
        [, $plainState] = $this->makeLinkAttempt($linkingUser);

        $response = $this->get($this->linkCallbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('social_identity_conflict', $params['error'] ?? null);
        $this->assertSame(0, UserSocialAccount::query()
            ->where('user_id', $linkingUser->id)->count());
    }

    public function test_link_callback_user_already_has_provider_returns_provider_already_linked(): void
    {
        $user = $this->makeUserWithPassword();
        UserSocialAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'g-existing-different',
        ]);

        // Different google identity — user already has google linked.
        $this->fakeAdapter->configureUser($this->makeSocialUser(providerId: 'g-new-different'));
        [, $plainState] = $this->makeLinkAttempt($user);

        $response = $this->get($this->linkCallbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('provider_already_linked', $params['error'] ?? null);
        $this->assertSame(1, UserSocialAccount::query()
            ->where('user_id', $user->id)->where('provider', 'google')->count());
    }

    public function test_link_callback_does_not_link_by_email_coincidence(): void
    {
        // User B exists with same email as what the social provider returns.
        $existingUser = User::factory()->create(['email' => 'shared@example.com']);
        $linkingUser = $this->makeUserWithPassword();

        $this->fakeAdapter->configureUser($this->makeSocialUser(
            providerId: 'g-email-match-test',
            email: 'shared@example.com',
            emailVerified: true,
        ));
        [, $plainState] = $this->makeLinkAttempt($linkingUser);

        $response = $this->get($this->linkCallbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        // Link must go to $linkingUser, not $existingUser.
        $this->assertSame('social_linked', $params['outcome'] ?? null);
        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $linkingUser->id,
            'provider_user_id' => 'g-email-match-test',
        ]);
        $this->assertSame(0, UserSocialAccount::query()
            ->where('user_id', $existingUser->id)->count());
    }

    public function test_link_callback_facebook_email_not_marked_as_verified(): void
    {
        $user = $this->makeUserWithPassword();

        // Facebook: email present but emailVerified=false (real adapter always returns false for FB).
        $this->fakeAdapter->configureUser($this->makeSocialUser(
            provider: 'facebook',
            providerId: 'fb-link-test',
            email: 'fbuser@example.com',
            emailVerified: false,
        ));
        [, $plainState] = $this->makeLinkAttempt($user, 'facebook');

        $this->get($this->linkCallbackUrl('facebook', $plainState));

        $account = UserSocialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', 'facebook')
            ->firstOrFail();

        $this->assertNull($account->provider_email_verified_at,
            'Facebook email must not be marked verified (no explicit evidence).');
        $this->assertSame('fbuser@example.com', $account->provider_email,
            'Email is stored as informational even when unverified.');
    }

    public function test_link_callback_repeated_does_not_call_adapter_again(): void
    {
        $user = $this->makeUserWithPassword();
        $this->fakeAdapter->configureUser($this->makeSocialUser());
        [, $plainState] = $this->makeLinkAttempt($user);

        // First callback — adapter called once.
        $this->get($this->linkCallbackUrl('google', $plainState));
        $this->assertSame(1, $this->fakeAdapter->getResolveUserCallCount());

        // Second callback — rejected at pre-check; adapter must not be invoked again.
        $this->get($this->linkCallbackUrl('google', $plainState));
        $this->assertSame(1, $this->fakeAdapter->getResolveUserCallCount());
    }

    public function test_link_callback_google_link_for_both_google_and_facebook(): void
    {
        $user = $this->makeUserWithPassword();

        $this->fakeAdapter->configureUser($this->makeSocialUser(provider: 'google', providerId: 'g-multi'));
        [, $stateGoogle] = $this->makeLinkAttempt($user, 'google');
        $this->get($this->linkCallbackUrl('google', $stateGoogle));

        $this->fakeAdapter->configureUser($this->makeSocialUser(
            provider: 'facebook',
            providerId: 'fb-multi',
            emailVerified: false,
        ));
        [, $stateFb] = $this->makeLinkAttempt($user, 'facebook');
        $response = $this->get($this->linkCallbackUrl('facebook', $stateFb));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('social_linked', $params['outcome'] ?? null);
        $this->assertSame(2, UserSocialAccount::query()->where('user_id', $user->id)->count());
    }

    // ─── Unlink ───────────────────────────────────────────────────────────────

    public function test_unlink_requires_authentication(): void
    {
        $this->deleteJson('/api/v1/auth/social/google')->assertUnauthorized();
    }

    public function test_unlink_with_password_requires_current_password(): void
    {
        $user = $this->makeUserWithPassword();
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);

        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/social/google')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_unlink_with_correct_password_succeeds(): void
    {
        $user = $this->makeUserWithPassword('my-secret');
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);

        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/social/google', ['current_password' => 'my-secret'])
            ->assertOk()
            ->assertJsonPath('provider', 'google');

        $this->assertSame(0, UserSocialAccount::query()
            ->where('user_id', $user->id)->where('provider', 'google')->count());
    }

    public function test_unlink_with_incorrect_password_fails(): void
    {
        $user = $this->makeUserWithPassword('correct-pw');
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);

        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/social/google', ['current_password' => 'wrong-pw'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_current_password');
    }

    public function test_unlink_social_only_user_does_not_require_password(): void
    {
        $user = $this->makeUserWithoutPassword();
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);
        UserSocialAccount::factory()->facebook()->create(['user_id' => $user->id]);

        // Social-only user must present a recent social-login token; no password required.
        $this->withToken($this->makeSocialToken($user, 'google'))
            ->deleteJson('/api/v1/auth/social/google')
            ->assertOk();

        $this->assertSame(0, UserSocialAccount::query()
            ->where('user_id', $user->id)->where('provider', 'google')->count());
    }

    public function test_unlink_preserves_other_social_accounts(): void
    {
        $user = $this->makeUserWithPassword('pw');
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);
        UserSocialAccount::factory()->facebook()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/social/google', ['current_password' => 'pw'])
            ->assertOk();

        $this->assertSame(1, UserSocialAccount::query()
            ->where('user_id', $user->id)->where('provider', 'facebook')->count());
    }

    public function test_unlink_does_not_delete_user(): void
    {
        $user = $this->makeUserWithPassword('pw');
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);

        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/social/google', ['current_password' => 'pw'])
            ->assertOk();

        $this->assertModelExists($user);
    }

    public function test_unlink_does_not_change_user_role(): void
    {
        $user = $this->makeUserWithPassword('pw');
        $roleBefore = $user->role->value;
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);

        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/social/google', ['current_password' => 'pw']);

        $this->assertSame($roleBefore, $user->fresh()->role->value);
    }

    public function test_unlink_last_authentication_method_fails(): void
    {
        $user = $this->makeUserWithoutPassword();
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);

        // Reauth passes (recent social token), then the last-method guard triggers.
        $this->withToken($this->makeSocialToken($user, 'google'))
            ->deleteJson('/api/v1/auth/social/google')
            ->assertStatus(422)
            ->assertJsonPath('error', 'last_authentication_method');

        $this->assertSame(1, UserSocialAccount::query()
            ->where('user_id', $user->id)->count());
    }

    public function test_unlink_when_only_social_but_has_password(): void
    {
        $user = $this->makeUserWithPassword('pw');
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);

        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/social/google', ['current_password' => 'pw'])
            ->assertOk();
    }

    public function test_unlink_provider_not_linked_returns_stable_error(): void
    {
        $user = $this->makeUserWithPassword('pw');

        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/social/google', ['current_password' => 'pw'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'not_linked');
    }

    public function test_unlink_rate_limit_returns_stable_response(): void
    {
        $user = $this->makeUserWithPassword('pw');
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)
                ->deleteJson('/api/v1/auth/social/google', ['current_password' => 'pw']);
        }

        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/social/google', ['current_password' => 'pw'])
            ->assertTooManyRequests()
            ->assertJsonPath('error', 'too_many_requests');
    }

    // ─── Social reautenticación ───────────────────────────────────────────────

    public function test_social_only_user_with_local_token_cannot_unlink(): void
    {
        $user = $this->makeUserWithoutPassword();
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);
        UserSocialAccount::factory()->facebook()->create(['user_id' => $user->id]);

        // Token named 'local-auth' with no social_reauth ability.
        $token = $user->createToken('local-auth')->plainTextToken;

        $this->withToken($token)
            ->deleteJson('/api/v1/auth/social/google')
            ->assertStatus(422)
            ->assertJsonPath('error', 'reauthentication_required');
    }

    public function test_social_only_user_with_expired_social_token_cannot_unlink(): void
    {
        $user = $this->makeUserWithoutPassword();
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);
        UserSocialAccount::factory()->facebook()->create(['user_id' => $user->id]);

        // Token is 400 s old; TTL is 300 s → expired.
        $token = $this->makeExpiredSocialToken($user, 'google', ageSeconds: 400);

        $this->withToken($token)
            ->deleteJson('/api/v1/auth/social/google')
            ->assertStatus(422)
            ->assertJsonPath('error', 'reauthentication_required');
    }

    public function test_social_only_user_with_recent_social_token_can_unlink(): void
    {
        $user = $this->makeUserWithoutPassword();
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);
        UserSocialAccount::factory()->facebook()->create(['user_id' => $user->id]);

        $this->withToken($this->makeSocialToken($user, 'google'))
            ->deleteJson('/api/v1/auth/social/google')
            ->assertOk()
            ->assertJsonPath('provider', 'google');

        $this->assertSame(0, UserSocialAccount::query()
            ->where('user_id', $user->id)->where('provider', 'google')->count());
    }

    public function test_social_only_unlink_fails_when_token_provider_no_longer_linked(): void
    {
        // Token is 'social:google' but Google was removed from the user's account
        // (e.g. by a concurrent session). The provider check inside the action
        // detects this and rejects the request.
        $user = $this->makeUserWithoutPassword();
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);
        UserSocialAccount::factory()->facebook()->create(['user_id' => $user->id]);

        $token = $this->makeSocialToken($user, 'google');

        // Remove Google manually (simulates a concurrent unlink or admin removal).
        UserSocialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', 'google')
            ->delete();

        // The google token is now invalid for reauth since Google is no longer linked.
        $this->withToken($token)
            ->deleteJson('/api/v1/auth/social/facebook')
            ->assertStatus(422)
            ->assertJsonPath('error', 'reauthentication_required');
    }

    public function test_transient_token_from_acting_as_is_rejected_for_social_only_unlink(): void
    {
        // actingAs() produces a TransientToken (no PersonalAccessToken in DB).
        // The action must reject it, forcing use of a real recent social token.
        $user = $this->makeUserWithoutPassword();
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);
        UserSocialAccount::factory()->facebook()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/social/google')
            ->assertStatus(422)
            ->assertJsonPath('error', 'reauthentication_required');
    }

    public function test_password_user_unlink_is_unaffected_by_social_reauth_rules(): void
    {
        // Users with a password always use current_password; social token is irrelevant.
        $user = $this->makeUserWithPassword('pw');
        UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);

        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/social/google', ['current_password' => 'pw'])
            ->assertOk();
    }

    // ─── Regression — 5.4 login flow still works ─────────────────────────────

    public function test_social_login_flow_still_works_after_5_5(): void
    {
        $this->fakeAdapter->configureUser(new SocialUserData(
            'google',
            'g-regression-555',
            'regression555@example.com',
            true,
            'Regression User',
        ));

        $attempt = OauthAttempt::create([
            'provider' => 'google',
            'purpose' => 'login',
            'state_hash' => hash('sha256', $state = Str::random(64)),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->get("/api/v1/auth/social/google/callback?state={$state}&code=fake");
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertArrayHasKey('code', $params, 'Login flow must still produce exchange code');
    }
}
