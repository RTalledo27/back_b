<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlayerActivationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makePendingPlayer(): User
    {
        return User::factory()->unverified()->create(['password' => null]);
    }

    private function makeInvitation(User $user, string $plainToken): UserInvitation
    {
        return UserInvitation::factory()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
        ]);
    }

    // ─── Happy path ──────────────────────────────────────────────────────────

    public function test_valid_token_sets_password_and_returns_sanctum_token(): void
    {
        $user = $this->makePendingPlayer();
        $plainToken = Str::random(64);
        $this->makeInvitation($user, $plainToken);

        $response = $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $response
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.abilities', ['auth:logout', 'player:access', 'user:read'])
            ->assertJsonPath('data.user.role', 'player');

        $this->assertNotNull($response->json('data.access_token'));
    }

    public function test_password_is_bcrypt_hashed_after_activation(): void
    {
        $user = $this->makePendingPlayer();
        $plainToken = Str::random(64);
        $this->makeInvitation($user, $plainToken);

        $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'secret12345',
            'password_confirmation' => 'secret12345',
        ])->assertOk();

        $user->refresh();
        $this->assertNotNull($user->password);
        $this->assertNotSame('secret12345', $user->password);
        $this->assertTrue(Hash::check('secret12345', (string) $user->password));
    }

    public function test_invitation_is_consumed_after_activation(): void
    {
        $user = $this->makePendingPlayer();
        $plainToken = Str::random(64);
        $invitation = $this->makeInvitation($user, $plainToken);

        $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        $invitation->refresh();
        $this->assertNotNull($invitation->consumed_at);
        $this->assertNull($invitation->revoked_at);
        $this->assertTrue($invitation->isConsumed());
    }

    public function test_activation_does_not_change_user_role(): void
    {
        $user = $this->makePendingPlayer();
        $plainToken = Str::random(64);
        $this->makeInvitation($user, $plainToken);

        $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'mypassword1',
            'password_confirmation' => 'mypassword1',
        ])->assertOk();

        $user->refresh();
        $this->assertSame(UserRole::Player, $user->role);
    }

    public function test_sanctum_token_grants_access_to_player_routes(): void
    {
        $user = $this->makePendingPlayer();
        $plainToken = Str::random(64);
        $this->makeInvitation($user, $plainToken);

        $response = $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        $sanctumToken = (string) $response->json('data.access_token');

        $this->withToken($sanctumToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    // ─── Token failure paths ──────────────────────────────────────────────────

    public function test_invalid_token_returns_stable_error(): void
    {
        $this->postJson('/api/v1/auth/activate', [
            'token' => Str::random(64),
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'invalid_activation_token')
            ->assertJsonPath('reason', 'not_found');
    }

    public function test_expired_token_returns_stable_error(): void
    {
        $user = $this->makePendingPlayer();
        $plainToken = Str::random(64);
        UserInvitation::factory()->expired()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
        ]);

        $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'invalid_activation_token')
            ->assertJsonPath('reason', 'expired');
    }

    public function test_revoked_token_returns_stable_error(): void
    {
        $user = $this->makePendingPlayer();
        $plainToken = Str::random(64);
        UserInvitation::factory()->revoked()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
        ]);

        $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'invalid_activation_token')
            ->assertJsonPath('reason', 'revoked');
    }

    public function test_already_consumed_token_returns_stable_error(): void
    {
        $user = $this->makePendingPlayer();
        $plainToken = Str::random(64);
        UserInvitation::factory()->consumed()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
        ]);

        $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'invalid_activation_token')
            ->assertJsonPath('reason', 'consumed');
    }

    public function test_token_is_single_use_second_attempt_fails(): void
    {
        $user = $this->makePendingPlayer();
        $plainToken = Str::random(64);
        $this->makeInvitation($user, $plainToken);

        $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'newpassword456',
            'password_confirmation' => 'newpassword456',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'invalid_activation_token')
            ->assertJsonPath('reason', 'consumed');
    }

    public function test_does_not_overwrite_existing_password(): void
    {
        $originalPasswordHash = Hash::make('original');
        $user = User::factory()->create(['password' => $originalPasswordHash]);

        $plainToken = Str::random(64);
        UserInvitation::factory()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
        ]);

        $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'invalid_activation_token')
            ->assertJsonPath('reason', 'already_active');

        $user->refresh();
        $this->assertSame($originalPasswordHash, $user->password);
    }

    // ─── Rollback integrity ───────────────────────────────────────────────────

    public function test_rollback_preserves_user_and_invitation_coherence(): void
    {
        $user = $this->makePendingPlayer();
        $plainToken = Str::random(64);
        $invitation = UserInvitation::factory()->consumed()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
        ]);

        $passwordBefore = $user->password;

        $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422);

        // User unchanged
        $user->refresh();
        $this->assertSame($passwordBefore, $user->password);

        // Invitation unchanged
        $invitation->refresh();
        $this->assertTrue($invitation->isConsumed());
        $this->assertNull($invitation->revoked_at);
    }

    // ─── Security ────────────────────────────────────────────────────────────

    public function test_response_does_not_expose_token_hash_or_password(): void
    {
        $user = $this->makePendingPlayer();
        $plainToken = Str::random(64);
        $this->makeInvitation($user, $plainToken);

        $body = $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk()->json();

        $json = json_encode($body, JSON_THROW_ON_ERROR);

        foreach (['token_hash', 'password', 'remember_token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }

        // Plain token must not appear in the response
        $this->assertStringNotContainsString($plainToken, $json);
    }

    public function test_error_responses_do_not_expose_hash(): void
    {
        $user = $this->makePendingPlayer();
        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);

        UserInvitation::factory()->consumed()->create([
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
        ]);

        $body = $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)->json();

        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($tokenHash, $json);
        $this->assertStringNotContainsString($plainToken, $json);
    }

    // ─── Rate limit ──────────────────────────────────────────────────────────

    public function test_activate_rate_limit_returns_stable_response(): void
    {
        $plainToken = Str::random(64);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/activate', [
                'token' => $plainToken,
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);
        }

        $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertTooManyRequests()
            ->assertJsonPath('error', 'too_many_requests')
            ->assertJsonPath('message', 'Too many authentication attempts.');
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    public function test_validation_requires_token_and_password(): void
    {
        $this->postJson('/api/v1/auth/activate', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token', 'password']);
    }

    public function test_password_must_be_confirmed(): void
    {
        $this->postJson('/api/v1/auth/activate', [
            'token' => Str::random(64),
            'password' => 'password123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_password_minimum_length_is_enforced(): void
    {
        $this->postJson('/api/v1/auth/activate', [
            'token' => Str::random(64),
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // ─── Regression ──────────────────────────────────────────────────────────

    public function test_local_auth_register_and_login_still_work_after_block_5_3(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Regress User',
            'email' => 'regress@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'regress@example.com',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_admin_routes_still_require_admin_role(): void
    {
        $this->postJson('/api/v1/admin/players', ['name' => 'X', 'email' => 'x@x.com'])
            ->assertUnauthorized();

        $player = User::factory()->create();
        $this->actingAs($player)
            ->postJson('/api/v1/admin/players', ['name' => 'X', 'email' => 'x@x.com'])
            ->assertForbidden();
    }
}
