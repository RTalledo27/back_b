<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Auth\AuthenticateUserAction;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class LocalAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_register_creates_player_with_normalized_email_and_valid_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => '  Ada Lovelace  ',
            'email' => 'ADA@Example.COM ',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated();

        $response
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.abilities', ['auth:logout', 'player:access', 'user:read'])
            ->assertJsonPath('data.user.name', 'Ada Lovelace')
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.user.role', 'player')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.user.email_verified_at', null);

        $token = (string) $response->json('data.access_token');
        $this->assertNotSame('', $token);

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        $this->assertSame(UserRole::Player, $user->role);
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret123', (string) $user->password));
        $this->assertNotSame('secret123', $user->password);

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'ada@example.com');
    }

    public function test_register_rejects_role_and_privileged_fields(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => 'admin',
            'permissions' => ['*'],
            'abilities' => ['admin:access'],
            'email_verified_at' => now()->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['role', 'permissions', 'abilities', 'email_verified_at']);

        $this->assertSame(0, User::query()->where('email', 'mallory@example.com')->count());
    }

    public function test_register_rejects_normalized_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Taken',
            'email' => 'TAKEN@EXAMPLE.COM',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_requires_local_password_even_when_password_can_be_nullable(): void
    {
        User::factory()->create([
            'email' => 'social-only@example.com',
            'password' => null,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'No Password',
            'email' => 'local@example.com',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Local User',
            'email' => 'local@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated();
    }

    public function test_register_validation_is_stable(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_register_rate_limit_returns_stable_response(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'Rate Limited',
                'email' => 'rate-register@example.com',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ])->assertStatus($attempt === 1 ? 201 : 422);
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Rate Limited',
            'email' => 'rate-register@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertTooManyRequests()
            ->assertJsonPath('error', 'too_many_requests')
            ->assertJsonPath('message', 'Too many authentication attempts.');
    }

    public function test_register_response_does_not_contain_private_fields(): void
    {
        $body = $this->postJson('/api/v1/auth/register', [
            'name' => 'Private Fields',
            'email' => 'private-fields@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated()->json();

        $json = json_encode($body, JSON_THROW_ON_ERROR);

        foreach (['password', 'remember_token', 'token_hash', 'provider_user_id', 'social_accounts', 'invitations'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_login_with_valid_credentials_creates_valid_token(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => ' LOGIN@EXAMPLE.COM ',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'login@example.com')
            ->assertJsonPath('data.abilities', ['auth:logout', 'player:access', 'user:read']);

        $this->withToken((string) $response->json('data.access_token'))
            ->getJson('/api/v1/me/orders')
            ->assertOk();
    }

    public function test_login_failures_are_uniform_for_wrong_password_missing_user_and_null_password(): void
    {
        User::factory()->create([
            'email' => 'wrong-password@example.com',
        ]);
        User::factory()->create([
            'email' => 'passwordless@example.com',
            'password' => null,
        ]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'wrong-password@example.com',
            'password' => 'bad-password',
        ]);
        $missingUser = $this->postJson('/api/v1/auth/login', [
            'email' => 'missing-user@example.com',
            'password' => 'bad-password',
        ]);
        $passwordless = $this->postJson('/api/v1/auth/login', [
            'email' => 'passwordless@example.com',
            'password' => 'bad-password',
        ]);

        foreach ([$wrongPassword, $missingUser, $passwordless] as $response) {
            $response->assertStatus(422)
                ->assertJsonPath('message', AuthenticateUserAction::INVALID_CREDENTIALS_MESSAGE)
                ->assertJsonPath('errors.email.0', AuthenticateUserAction::INVALID_CREDENTIALS_MESSAGE);
        }

        $this->assertNull(User::query()->where('email', 'passwordless@example.com')->firstOrFail()->password);
    }

    public function test_login_token_keeps_player_out_of_admin_routes(): void
    {
        User::factory()->create([
            'email' => 'player-login@example.com',
        ]);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'player-login@example.com',
            'password' => 'password',
        ])->assertOk()->json('data.access_token');

        $this->withToken($token)
            ->postJson('/api/v1/admin/games', [])
            ->assertStatus(403);
    }

    public function test_login_rate_limit_returns_stable_response(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'rate-login@example.com',
                'password' => 'bad-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'rate-login@example.com',
            'password' => 'bad-password',
        ])->assertTooManyRequests()
            ->assertJsonPath('error', 'too_many_requests')
            ->assertJsonPath('message', 'Too many authentication attempts.');
    }

    public function test_logout_revokes_only_current_token(): void
    {
        $user = User::factory()->create();
        $firstToken = $user->createToken('first')->plainTextToken;
        $secondToken = $user->createToken('second')->plainTextToken;

        $this->withToken($firstToken)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertNull(PersonalAccessToken::findToken($firstToken));
        $this->assertNotNull(PersonalAccessToken::findToken($secondToken));

        Auth::forgetGuards();

        $this->withToken($firstToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $this->withToken($secondToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $user->id)->count());
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_me_and_legacy_user_alias_share_the_same_public_contract(): void
    {
        $user = User::factory()->create([
            'email' => 'me@example.com',
        ]);
        $token = $user->createToken('me')->plainTextToken;

        $me = $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'role',
                    'email_verified',
                    'email_verified_at',
                    'capabilities' => ['can_access_admin', 'can_use_player_features'],
                ],
            ])
            ->assertJsonPath('data.email', 'me@example.com')
            ->assertJsonPath('data.role', 'player')
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.email_verified_at', $user->email_verified_at?->utc()->toIso8601String())
            ->assertJsonPath('data.capabilities.can_access_admin', false)
            ->assertJsonPath('data.capabilities.can_use_player_features', true);

        $legacy = $this->withToken($token)
            ->getJson('/api/v1/user')
            ->assertOk();

        $this->assertSame($me->json(), $legacy->json());

        $json = json_encode($me->json(), JSON_THROW_ON_ERROR);
        foreach (['password', 'remember_token', 'access_token', 'provider_user_id', 'token_hash', 'invitations'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_admin_route_authentication_matrix_still_returns_401_and_403(): void
    {
        $this->postJson('/api/v1/admin/games', [])->assertStatus(401);

        $token = User::factory()->create()->createToken('player')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/admin/games', [])
            ->assertStatus(403);
    }
}
