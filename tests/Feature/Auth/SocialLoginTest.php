<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\DTOs\Auth\SocialUserData;
use App\Enums\UserRole;
use App\Models\OauthAttempt;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\Auth\SocialProviderAdapter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\FakeSocialProviderAdapter;
use Tests\TestCase;

final class SocialLoginTest extends TestCase
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

    private function makeAttempt(string $provider = 'google', ?string $plainState = null, int $ttlSeconds = 600): array
    {
        $plainState ??= Str::random(64);
        $attempt = OauthAttempt::create([
            'provider' => $provider,
            'state_hash' => hash('sha256', $plainState),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        return [$attempt, $plainState];
    }

    private function makeSocialUser(
        string $provider = 'google',
        string $providerId = 'prov-id-123',
        ?string $email = 'social@example.com',
        bool $emailVerified = true,
        string $name = 'Social User',
    ): SocialUserData {
        return new SocialUserData($provider, $providerId, $email, $emailVerified, $name);
    }

    private function callbackUrl(string $provider, string $plainState, string $code = 'fake-code'): string
    {
        return "/api/v1/auth/social/{$provider}/callback?state={$plainState}&code={$code}";
    }

    private function parseRedirectParams(string $redirectUrl): array
    {
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $params);

        return $params;
    }

    // ─── Redirect endpoint ────────────────────────────────────────────────────

    public function test_redirect_initiates_oauth_flow_for_google(): void
    {
        $this->get('/api/v1/auth/social/google/redirect')
            ->assertRedirect();

        $this->assertDatabaseCount('oauth_attempts', 1);
        $this->assertDatabaseHas('oauth_attempts', ['provider' => 'google', 'consumed_at' => null]);
    }

    public function test_redirect_initiates_oauth_flow_for_facebook(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser('facebook', 'fb-123'));

        $this->get('/api/v1/auth/social/facebook/redirect')
            ->assertRedirect();

        $this->assertDatabaseCount('oauth_attempts', 1);
        $this->assertDatabaseHas('oauth_attempts', ['provider' => 'facebook']);
    }

    public function test_redirect_invalid_provider_returns_404(): void
    {
        $this->get('/api/v1/auth/social/twitter/redirect')
            ->assertNotFound();
    }

    public function test_redirect_missing_configuration_returns_503(): void
    {
        $this->fakeAdapter->configureMissing('google');

        $this->getJson('/api/v1/auth/social/google/redirect')
            ->assertStatus(503)
            ->assertJsonPath('error', 'provider_not_configured');
    }

    public function test_redirect_creates_attempt_with_hashed_state_only(): void
    {
        $response = $this->get('/api/v1/auth/social/google/redirect');
        $response->assertRedirect();

        $redirectTarget = (string) $response->headers->get('Location');
        parse_str((string) parse_url($redirectTarget, PHP_URL_QUERY), $q);
        $plainState = $q['state'] ?? '';

        $this->assertNotEmpty($plainState);
        $this->assertDatabaseMissing('oauth_attempts', ['state_hash' => $plainState]);
        $this->assertDatabaseHas('oauth_attempts', ['state_hash' => hash('sha256', $plainState)]);
    }

    // ─── Callback — state validation ──────────────────────────────────────────

    public function test_callback_with_oauth_error_param_redirects_with_oauth_error(): void
    {
        $response = $this->get('/api/v1/auth/social/google/callback?error=access_denied');
        $response->assertRedirect();

        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));
        $this->assertSame('oauth_error', $params['error'] ?? null);
    }

    public function test_callback_missing_code_redirects_with_oauth_error(): void
    {
        $response = $this->get('/api/v1/auth/social/google/callback?state='.Str::random(64));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('oauth_error', $params['error'] ?? null);
    }

    public function test_callback_invalid_state_redirects_with_invalid_state(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser());

        $response = $this->get($this->callbackUrl('google', Str::random(64)));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('invalid_state', $params['error'] ?? null);
    }

    public function test_callback_expired_state_redirects_with_expired_state(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser());
        [, $plainState] = $this->makeAttempt(ttlSeconds: -1);

        $response = $this->get($this->callbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('expired_state', $params['error'] ?? null);
    }

    public function test_callback_already_processed_state_redirects_with_already_processed(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser());
        [, $plainState] = $this->makeAttempt();

        // First call — processes successfully.
        $this->get($this->callbackUrl('google', $plainState));

        // Second call — must be rejected.
        $response = $this->get($this->callbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('callback_already_processed', $params['error'] ?? null);
    }

    // ─── Callback — identity resolution ──────────────────────────────────────

    public function test_callback_new_verified_email_creates_player(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser(
            email: 'newuser@example.com',
            emailVerified: true,
        ));
        [, $plainState] = $this->makeAttempt();

        $response = $this->get($this->callbackUrl('google', $plainState));
        $response->assertRedirect();

        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));
        $this->assertArrayHasKey('code', $params);
        $this->assertArrayNotHasKey('error', $params);

        $user = User::query()->where('email', 'newuser@example.com')->firstOrFail();
        $this->assertSame(UserRole::Player, $user->role);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_callback_new_player_has_null_password(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser(email: 'nullpw@example.com'));
        [, $plainState] = $this->makeAttempt();

        $this->get($this->callbackUrl('google', $plainState));

        $user = User::query()->where('email', 'nullpw@example.com')->firstOrFail();
        $this->assertNull($user->password);
    }

    public function test_callback_existing_social_identity_authenticates_same_user(): void
    {
        $user = User::factory()->create(['password' => null]);
        UserSocialAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'g-existing-123',
        ]);

        $this->fakeAdapter->configureUser($this->makeSocialUser(providerId: 'g-existing-123'));
        [, $plainState] = $this->makeAttempt();

        $response = $this->get($this->callbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertArrayHasKey('code', $params);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, UserSocialAccount::query()->count());
    }

    public function test_callback_email_exists_returns_account_link_required(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->fakeAdapter->configureUser($this->makeSocialUser(email: 'taken@example.com'));
        [, $plainState] = $this->makeAttempt();

        $response = $this->get($this->callbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('account_link_required', $params['error'] ?? null);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, UserSocialAccount::query()->count());
    }

    public function test_callback_no_email_returns_verified_email_required(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser(email: null, emailVerified: false));
        [, $plainState] = $this->makeAttempt();

        $response = $this->get($this->callbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('verified_email_required', $params['error'] ?? null);
        $this->assertSame(0, User::query()->count());
    }

    public function test_callback_unverified_email_returns_verified_email_required(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser(
            email: 'unverified@example.com',
            emailVerified: false,
        ));
        [, $plainState] = $this->makeAttempt();

        $response = $this->get($this->callbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('verified_email_required', $params['error'] ?? null);
        $this->assertSame(0, User::query()->count());
    }

    public function test_callback_does_not_auto_link_by_email(): void
    {
        $existingUser = User::factory()->create(['email' => 'linked@example.com']);

        $this->fakeAdapter->configureUser($this->makeSocialUser(
            email: 'linked@example.com',
            emailVerified: true,
        ));
        [, $plainState] = $this->makeAttempt();

        $this->get($this->callbackUrl('google', $plainState));

        $this->assertSame(0, UserSocialAccount::query()->where('user_id', $existingUser->id)->count());
        $this->assertSame(1, User::query()->count());
    }

    public function test_callback_repeated_does_not_create_duplicate_user_or_social_account(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser(email: 'dup@example.com'));
        [, $plainState] = $this->makeAttempt();

        // First callback — success.
        $this->get($this->callbackUrl('google', $plainState));
        $this->assertSame(1, User::query()->count());

        // Second callback with same state — rejected.
        $this->get($this->callbackUrl('google', $plainState));
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, UserSocialAccount::query()->count());
    }

    public function test_facebook_linked_account_authenticates_via_provider_identity(): void
    {
        $user = User::factory()->create();
        UserSocialAccount::factory()->facebook()->create([
            'user_id' => $user->id,
            'provider_user_id' => 'fb-existing-456',
        ]);

        // Facebook returns no email — that's fine: (provider, provider_user_id) is sufficient.
        $this->fakeAdapter->configureUser($this->makeSocialUser(
            provider: 'facebook',
            providerId: 'fb-existing-456',
            email: null,
            emailVerified: false,
        ));
        [, $plainState] = $this->makeAttempt('facebook');

        $response = $this->get($this->callbackUrl('facebook', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertArrayHasKey('code', $params);
        $this->assertArrayNotHasKey('error', $params);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, UserSocialAccount::query()->count());
    }

    public function test_facebook_with_email_but_no_explicit_verification_returns_verified_email_required(): void
    {
        // The real SocialiteProviderAdapter always returns emailVerified=false for Facebook
        // (Graph API provides no explicit email_verified field). The fake models that exact
        // production behaviour so the outcome is verified_email_required for a new identity.
        $this->fakeAdapter->configureUser($this->makeSocialUser(
            provider: 'facebook',
            providerId: 'fb-unverified-789',
            email: 'fbuser@example.com',
            emailVerified: false,
        ));
        [, $plainState] = $this->makeAttempt('facebook');

        $response = $this->get($this->callbackUrl('facebook', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $this->assertSame('verified_email_required', $params['error'] ?? null);
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, UserSocialAccount::query()->count());
    }

    // ─── Stateless OAuth & single-use callback ───────────────────────────────

    public function test_callback_succeeds_without_session_cookies_stateless(): void
    {
        // ① Redirect — creates OauthAttempt; state_hash stored in DB, never in session.
        $redirectResponse = $this->get('/api/v1/auth/social/google/redirect');
        parse_str((string) parse_url((string) $redirectResponse->headers->get('Location'), PHP_URL_QUERY), $q);
        $plainState = $q['state'] ?? '';
        $this->assertNotEmpty($plainState);

        // ② Wipe all session data — simulates a callback arriving from a fresh browser
        //    with no shared state from the redirect phase.
        $this->withSession([]);

        // ③ Callback with correct state must succeed: DB is the sole authority.
        $this->fakeAdapter->configureUser($this->makeSocialUser());
        $callbackParams = $this->parseRedirectParams(
            (string) $this->get($this->callbackUrl('google', $plainState))->headers->get('Location'),
        );
        $this->assertArrayHasKey('code', $callbackParams, 'Callback must succeed after session wipe');

        // ④ Wrong state must still be rejected regardless of session state.
        $wrongParams = $this->parseRedirectParams(
            (string) $this->get($this->callbackUrl('google', Str::random(64)))->headers->get('Location'),
        );
        $this->assertSame('invalid_state', $wrongParams['error'] ?? null);
    }

    public function test_repeated_successful_callback_does_not_call_adapter_again(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser(email: 'singlecall@example.com'));
        [, $plainState] = $this->makeAttempt();

        // First callback — adapter called once.
        $this->get($this->callbackUrl('google', $plainState));
        $this->assertSame(1, $this->fakeAdapter->getResolveUserCallCount());

        // Second callback — rejected at pre-check; adapter must not be invoked again.
        $this->get($this->callbackUrl('google', $plainState));
        $this->assertSame(1, $this->fakeAdapter->getResolveUserCallCount());
    }

    public function test_repeated_callback_does_not_replace_exchange_code_hash(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser(email: 'noreplace@example.com'));
        [, $plainState] = $this->makeAttempt();

        $this->get($this->callbackUrl('google', $plainState));

        $originalHash = OauthAttempt::query()
            ->where('state_hash', hash('sha256', $plainState))
            ->value('exchange_code_hash');

        // Second callback — rejected.
        $this->get($this->callbackUrl('google', $plainState));

        $currentHash = OauthAttempt::query()
            ->where('state_hash', hash('sha256', $plainState))
            ->value('exchange_code_hash');

        $this->assertSame($originalHash, $currentHash,
            'Exchange code hash must not be replaced by a repeated callback');
    }

    public function test_first_exchange_code_remains_valid_after_repeated_callback_attempt(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser(email: 'firstvalid@example.com'));
        [, $plainState] = $this->makeAttempt();

        // First callback — produces a valid exchange code.
        $params = $this->parseRedirectParams(
            (string) $this->get($this->callbackUrl('google', $plainState))->headers->get('Location'),
        );
        $firstCode = $params['code'];

        // Second callback — rejected, no new code.
        $this->get($this->callbackUrl('google', $plainState));

        // Original exchange code must still work.
        $this->postJson('/api/v1/auth/social/exchange', ['code' => $firstCode])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer');
    }

    // ─── Exchange code security ───────────────────────────────────────────────

    public function test_callback_stores_only_exchange_code_hash_never_plain(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser(email: 'hashtest@example.com'));
        [, $plainState] = $this->makeAttempt();

        $response = $this->get($this->callbackUrl('google', $plainState));
        $params = $this->parseRedirectParams((string) $response->headers->get('Location'));

        $plainCode = $params['code'];
        $this->assertDatabaseMissing('oauth_attempts', ['exchange_code_hash' => $plainCode]);
        $this->assertDatabaseHas('oauth_attempts', ['exchange_code_hash' => hash('sha256', $plainCode)]);
    }

    // ─── Exchange endpoint ────────────────────────────────────────────────────

    public function test_exchange_valid_code_returns_sanctum_token(): void
    {
        $user = User::factory()->create(['password' => null]);
        $plainCode = Str::random(64);

        OauthAttempt::factory()
            ->withExchangeCode($plainCode)
            ->forUser($user)
            ->create();

        $response = $this->postJson('/api/v1/auth/social/exchange', ['code' => $plainCode]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.role', 'player');

        $this->assertNotNull($response->json('data.access_token'));
    }

    public function test_exchange_sanctum_token_grants_access_to_player_routes(): void
    {
        $user = User::factory()->create(['password' => null]);
        $plainCode = Str::random(64);
        OauthAttempt::factory()->withExchangeCode($plainCode)->forUser($user)->create();

        $token = $this->postJson('/api/v1/auth/social/exchange', ['code' => $plainCode])
            ->json('data.access_token');

        $this->withToken((string) $token)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_exchange_code_not_found_returns_stable_error(): void
    {
        $this->postJson('/api/v1/auth/social/exchange', ['code' => Str::random(64)])
            ->assertStatus(422)
            ->assertJsonPath('error', 'exchange_code_not_found');
    }

    public function test_exchange_code_expired_returns_stable_error(): void
    {
        $user = User::factory()->create(['password' => null]);
        $plainCode = Str::random(64);

        OauthAttempt::factory()
            ->withExchangeCode($plainCode)
            ->forUser($user)
            ->expired()
            ->create();

        $this->postJson('/api/v1/auth/social/exchange', ['code' => $plainCode])
            ->assertStatus(422)
            ->assertJsonPath('error', 'exchange_code_expired');
    }

    public function test_exchange_code_is_single_use(): void
    {
        $user = User::factory()->create(['password' => null]);
        $plainCode = Str::random(64);
        OauthAttempt::factory()->withExchangeCode($plainCode)->forUser($user)->create();

        $this->postJson('/api/v1/auth/social/exchange', ['code' => $plainCode])->assertOk();

        $this->postJson('/api/v1/auth/social/exchange', ['code' => $plainCode])
            ->assertStatus(422)
            ->assertJsonPath('error', 'exchange_code_consumed');
    }

    // ─── Token / secret exposure ──────────────────────────────────────────────

    public function test_sanctum_token_never_appears_in_callback_redirect_url(): void
    {
        $this->fakeAdapter->configureUser($this->makeSocialUser(email: 'notoken@example.com'));
        [, $plainState] = $this->makeAttempt();

        $response = $this->get($this->callbackUrl('google', $plainState));
        $redirectUrl = (string) $response->headers->get('Location');

        // The redirect URL is to the frontend; a Sanctum token starts with a numeric
        // prefix followed by '|'. A simple heuristic: check the URL doesn't have '|'.
        $this->assertStringNotContainsString('|', $redirectUrl,
            'A Sanctum token (which contains "|") must not appear in the redirect URL.');
    }

    public function test_oauth_access_tokens_are_not_stored_in_database(): void
    {
        // The social account table must only store provider_user_id and provider_email,
        // never the OAuth access token or refresh token.
        $columns = Schema::getColumnListing('user_social_accounts');

        $this->assertNotContains('access_token', $columns);
        $this->assertNotContains('refresh_token', $columns);
        $this->assertNotContains('id_token', $columns);
    }

    public function test_logs_do_not_contain_provider_access_tokens(): void
    {
        Log::spy();

        $this->fakeAdapter->configureUser($this->makeSocialUser(email: 'logtest@example.com'));
        [, $plainState] = $this->makeAttempt();

        $this->get($this->callbackUrl('google', $plainState));

        $plainCode = Str::random(64);
        $user = User::query()->where('email', 'logtest@example.com')->firstOrFail();
        OauthAttempt::factory()->withExchangeCode($plainCode)->forUser($user)->create();
        $this->postJson('/api/v1/auth/social/exchange', ['code' => $plainCode]);

        Log::shouldNotHaveReceived('info', function ($message): bool {
            $json = json_encode($message, JSON_THROW_ON_ERROR);

            return str_contains($json, 'access_token') || str_contains($json, 'refresh_token');
        });
    }

    public function test_exchange_response_does_not_expose_hashes_or_plain_code(): void
    {
        $user = User::factory()->create(['password' => null]);
        $plainCode = Str::random(64);
        OauthAttempt::factory()->withExchangeCode($plainCode)->forUser($user)->create();

        $body = $this->postJson('/api/v1/auth/social/exchange', ['code' => $plainCode])
            ->assertOk()
            ->json();

        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('state_hash', $json);
        $this->assertStringNotContainsString('exchange_code_hash', $json);
        $this->assertStringNotContainsString($plainCode, $json);
    }

    // ─── Unique constraints ───────────────────────────────────────────────────

    public function test_provider_user_id_unique_per_provider(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        UserSocialAccount::factory()->create([
            'user_id' => $user1->id,
            'provider' => 'google',
            'provider_user_id' => 'g-clash-id',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        UserSocialAccount::factory()->create([
            'user_id' => $user2->id,
            'provider' => 'google',
            'provider_user_id' => 'g-clash-id',
        ]);
    }

    public function test_user_can_have_one_account_per_provider(): void
    {
        $user = User::factory()->create();

        UserSocialAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'g-first',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        UserSocialAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'g-second',
        ]);
    }

    // ─── Rate limits ──────────────────────────────────────────────────────────

    public function test_redirect_rate_limit_returns_stable_response(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->get('/api/v1/auth/social/google/redirect');
        }

        $this->getJson('/api/v1/auth/social/google/redirect')
            ->assertTooManyRequests()
            ->assertJsonPath('error', 'too_many_requests');
    }

    public function test_exchange_rate_limit_returns_stable_response(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/auth/social/exchange', ['code' => Str::random(64)]);
        }

        $this->postJson('/api/v1/auth/social/exchange', ['code' => Str::random(64)])
            ->assertTooManyRequests()
            ->assertJsonPath('error', 'too_many_requests');
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    public function test_exchange_requires_code_of_64_characters(): void
    {
        $this->postJson('/api/v1/auth/social/exchange', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->postJson('/api/v1/auth/social/exchange', ['code' => 'short'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    // ─── Regression ──────────────────────────────────────────────────────────

    public function test_existing_auth_endpoints_still_work_after_block_5_4(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Regression User',
            'email' => 'regress54@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'regress54@example.com',
            'password' => 'password123',
        ])->assertOk();
    }
}
