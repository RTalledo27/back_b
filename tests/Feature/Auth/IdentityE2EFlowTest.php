<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\DTOs\Auth\SocialUserData;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\Auth\SocialProviderAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Support\FakeSocialProviderAdapter;
use Tests\TestCase;

/**
 * End-to-end identity flow chains.
 *
 * Each test drives a complete multi-step sequence via real HTTP requests and
 * real Sanctum tokens.  No Sanctum::actingAs(), no Google/Facebook APIs,
 * no Redis, no Reverb.
 *
 * Mapping to the five flows required by Block 5.7:
 *   1. Local       — register → token → me → logout → token rejected
 *   2. Assisted    — admin login → create player → activate → token → me
 *   3. Social      — redirect → callback (fake) → exchange → token → me
 *   4. Link        — local user → link social → list accounts → social login → same User
 *   5. Unlink      — real reauth token → unlink → other provider preserved
 *
 * Individual steps are covered in detail in their own feature-test files;
 * these tests verify the *chain* end-to-end.
 */
final class IdentityE2EFlowTest extends TestCase
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

    private function makeSocialUser(
        string $provider = 'google',
        string $providerId = 'prov-e2e-001',
        ?string $email = 'social-e2e@example.com',
        bool $emailVerified = true,
        string $name = 'E2E Social User',
    ): SocialUserData {
        return new SocialUserData($provider, $providerId, $email, $emailVerified, $name);
    }

    /**
     * Drives GET /auth/social/{provider}/redirect → GET /auth/social/{provider}/callback
     * and returns the redirect params from the callback response.
     *
     * @return array<string, string>
     */
    private function socialLoginRedirectThenCallback(string $provider, SocialUserData $socialUser): array
    {
        // Step A: redirect — creates an OauthAttempt; state is embedded in Location.
        $redirectResponse = $this->get("/api/v1/auth/social/{$provider}/redirect");
        $redirectResponse->assertRedirect();

        parse_str(
            (string) parse_url((string) $redirectResponse->headers->get('Location'), PHP_URL_QUERY),
            $q,
        );
        $plainState = (string) ($q['state'] ?? '');
        $this->assertNotEmpty($plainState, "redirect must produce a non-empty state for {$provider}");

        // Step B: callback — the fake adapter returns the configured user.
        $this->fakeAdapter->configureUser($socialUser);

        $callbackResponse = $this->get(
            "/api/v1/auth/social/{$provider}/callback?state={$plainState}&code=fake-code",
        );
        $callbackResponse->assertRedirect();

        $params = [];
        parse_str(
            (string) parse_url((string) $callbackResponse->headers->get('Location'), PHP_URL_QUERY),
            $params,
        );

        return $params;
    }

    /** Creates a real PersonalAccessToken with social_reauth ability (not a TransientToken). */
    private function makeSocialToken(User $user, string $provider = 'google'): string
    {
        return $user->createToken('social:'.$provider, ['social_reauth'])->plainTextToken;
    }

    // ─── 1. Local chain ───────────────────────────────────────────────────────

    /**
     * Chain: register → token → me → logout → token rejected.
     *
     * LocalAuthenticationTest covers register+token+me individually;
     * this test proves the logout step revokes the token end-to-end.
     */
    public function test_local_chain_register_token_me_logout_rejected(): void
    {
        // Step 1: register.
        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'E2E Local',
            'email' => 'e2e-local@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated();

        $token = (string) $registerResponse->json('data.access_token');
        $this->assertNotEmpty($token);

        // Step 2: token grants access to /auth/me.
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'e2e-local@example.com');

        // Step 3: logout revokes the token.
        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        // Step 4: same token is now rejected (Sanctum guard cache must be cleared).
        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    // ─── 2. Assisted chain ────────────────────────────────────────────────────

    /**
     * Chain: admin HTTP login → create player → activate → token → me.
     *
     * AssistedRegistrationTest and PlayerActivationTest cover each step;
     * this test proves the full end-to-end chain works without shortcuts.
     */
    public function test_assisted_chain_create_activate_token_me(): void
    {
        // Step 1: admin authenticates via local login.
        $admin = User::factory()->admin()->create([
            'email' => 'admin-assisted-e2e@example.com',
            'password' => 'secret123',
        ]);

        $adminToken = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-assisted-e2e@example.com',
            'password' => 'secret123',
        ])->assertOk()->json('data.access_token');

        $this->assertNotEmpty($adminToken);

        // Step 2: admin creates a pending player and receives the plain invitation token.
        $createResponse = $this->withToken($adminToken)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Assisted E2E Player',
                'email' => 'assisted-e2e@example.com',
            ])
            ->assertCreated();

        $plainToken = (string) $createResponse->json('data.plain_token');
        $this->assertNotEmpty($plainToken);

        // Step 3: player activates their account with the invitation token.
        $activateResponse = $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $playerToken = (string) $activateResponse->json('data.access_token');
        $this->assertNotEmpty($playerToken);

        // Clear the Sanctum guard cache so the next request re-resolves the player token.
        Auth::forgetGuards();

        // Step 4: activation token grants access to /auth/me.
        $this->withToken($playerToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'assisted-e2e@example.com')
            ->assertJsonPath('data.role', 'player');
    }

    // ─── 3. Social chain ──────────────────────────────────────────────────────

    /**
     * Chain: redirect → callback (fake adapter) → exchange → token → me.
     *
     * SocialLoginTest tests each step in isolation; this test proves the full
     * chain works without any session state and without a real OAuth provider.
     */
    public function test_social_chain_redirect_callback_exchange_token_me(): void
    {
        $socialUser = $this->makeSocialUser(
            providerId: 'g-e2e-login-001',
            email: 'social-login-e2e@example.com',
        );

        // Steps 1 & 2: redirect then callback → get exchange code from redirect params.
        $params = $this->socialLoginRedirectThenCallback('google', $socialUser);

        $this->assertArrayHasKey('code', $params, 'callback must produce an exchange code');
        $this->assertArrayNotHasKey('error', $params);

        // Step 3: exchange the one-time code for a Sanctum token.
        $exchangeResponse = $this->postJson('/api/v1/auth/social/exchange', [
            'code' => $params['code'],
        ])->assertOk();

        $token = (string) $exchangeResponse->json('data.access_token');
        $this->assertNotEmpty($token);

        // Step 4: token grants access to /auth/me with the new player's identity.
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'social-login-e2e@example.com')
            ->assertJsonPath('data.role', 'player');
    }

    // ─── 4. Link chain ────────────────────────────────────────────────────────

    /**
     * Chain: local user → link redirect → link callback → list accounts → social login → same User.
     *
     * Proves that after a local user links a social identity, they can authenticate
     * via that social identity and land on the same user record.
     */
    public function test_link_chain_local_user_links_google_then_logs_in_as_same_user(): void
    {
        $googleId = 'g-link-chain-'.Str::random(8);

        // Step 1: local user registers and obtains a token.
        $localToken = (string) $this->postJson('/api/v1/auth/register', [
            'name' => 'Link Chain User',
            'email' => 'link-chain-e2e@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated()->json('data.access_token');

        $user = User::query()->where('email', 'link-chain-e2e@example.com')->firstOrFail();

        // Step 2: user initiates a link redirect (authenticated).
        $linkRedirectLocation = (string) $this->withToken($localToken)
            ->get('/api/v1/auth/social/google/link/redirect')
            ->assertRedirect()
            ->headers->get('Location');

        parse_str((string) parse_url($linkRedirectLocation, PHP_URL_QUERY), $linkQ);
        $linkPlainState = (string) ($linkQ['state'] ?? '');
        $this->assertNotEmpty($linkPlainState);

        // Step 3: link callback links the google identity to the user.
        $this->fakeAdapter->configureUser($this->makeSocialUser(
            providerId: $googleId,
            email: 'link-chain-e2e@example.com',
        ));

        $callbackParams = [];
        parse_str(
            (string) parse_url(
                (string) $this->get("/api/v1/auth/social/google/link/callback?state={$linkPlainState}&code=fake-code")
                    ->assertRedirect()
                    ->headers->get('Location'),
                PHP_URL_QUERY,
            ),
            $callbackParams,
        );

        $this->assertSame('social_linked', $callbackParams['outcome'] ?? null);

        // Step 4: list social accounts — google must appear.
        $this->withToken($localToken)
            ->getJson('/api/v1/auth/social-accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider', 'google');

        // Step 5: the same google identity initiates a fresh social login.
        $loginParams = $this->socialLoginRedirectThenCallback(
            'google',
            $this->makeSocialUser(providerId: $googleId, email: 'link-chain-e2e@example.com'),
        );

        $this->assertArrayHasKey('code', $loginParams, 'social login for linked identity must produce exchange code');

        $socialToken = (string) $this->postJson('/api/v1/auth/social/exchange', [
            'code' => $loginParams['code'],
        ])->assertOk()->json('data.access_token');

        // Step 6: /auth/me via the social token must resolve to the same user.
        $this->withToken($socialToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'link-chain-e2e@example.com');
    }

    // ─── 5. Unlink chain ──────────────────────────────────────────────────────

    /**
     * Chain: real reauth token → unlink google → google gone → facebook preserved → user exists.
     *
     * Proves the full unlink flow for a social-only user:
     *   - requires a real PersonalAccessToken (not a TransientToken from actingAs)
     *   - removes the target provider
     *   - preserves other linked providers
     *   - does not delete the user
     */
    public function test_unlink_chain_social_only_user_keeps_other_provider_after_unlink(): void
    {
        // Setup: social-only user with both google and facebook linked.
        $user = User::factory()->create(['password' => null]);

        UserSocialAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'g-unlink-e2e-001',
        ]);

        UserSocialAccount::factory()->facebook()->create([
            'user_id' => $user->id,
        ]);

        // Step 1: obtain a real social reauth PersonalAccessToken (not a TransientToken).
        $reauthToken = $this->makeSocialToken($user, 'google');

        // Step 2: unlink google using the real token.
        $this->withToken($reauthToken)
            ->deleteJson('/api/v1/auth/social/google')
            ->assertOk()
            ->assertJsonPath('provider', 'google');

        // Step 3: google account is removed.
        $this->assertSame(
            0,
            UserSocialAccount::query()
                ->where('user_id', $user->id)
                ->where('provider', 'google')
                ->count(),
        );

        // Step 4: facebook account is preserved — user retains an auth method.
        $this->assertSame(
            1,
            UserSocialAccount::query()
                ->where('user_id', $user->id)
                ->where('provider', 'facebook')
                ->count(),
        );

        // Step 5: user record is not deleted.
        $this->assertModelExists($user);
    }
}
