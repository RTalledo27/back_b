<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Auth\CreatePlayerInvitationAction;
use App\DTOs\Auth\CreatePlayerData;
use App\Enums\CreatePlayerOutcome;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class AssistedRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ─── Creation via HTTP ────────────────────────────────────────────────────

    public function test_admin_can_create_pending_player_and_receives_invitation(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Alice',
                'email' => 'alice@example.com',
            ])
            ->assertCreated();

        $response
            ->assertJsonPath('data.outcome', 'invited')
            ->assertJsonPath('data.user.email', 'alice@example.com')
            ->assertJsonPath('data.user.role', 'player')
            ->assertJsonStructure(['data' => ['outcome', 'user', 'invitation', 'plain_token']]);

        $this->assertNotNull($response->json('data.plain_token'));
        $this->assertNotNull($response->json('data.invitation.id'));
        $this->assertNotNull($response->json('data.invitation.expires_at'));
    }

    public function test_player_receives_403(): void
    {
        $player = User::factory()->create();

        $this->actingAs($player)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Bob',
                'email' => 'bob@example.com',
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_visitor_receives_401(): void
    {
        $this->postJson('/api/v1/admin/players', [
            'name' => 'Carol',
            'email' => 'carol@example.com',
        ])->assertUnauthorized();
    }

    public function test_created_player_always_gets_player_role(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Dave',
                'email' => 'dave@example.com',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'player');

        $this->assertSame(
            UserRole::Player,
            User::query()->where('email', 'dave@example.com')->firstOrFail()->role
        );
    }

    public function test_attempt_to_assign_admin_role_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Mallory',
                'email' => 'mallory@example.com',
                'role' => 'admin',
                'permissions' => ['*'],
                'abilities' => ['admin:access'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role', 'permissions', 'abilities']);

        $this->assertSame(0, User::query()->where('email', 'mallory@example.com')->count());
    }

    public function test_email_is_normalized_before_storing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => '  Eve  ',
                'email' => '  EVE@EXAMPLE.COM  ',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'eve@example.com')
            ->assertJsonPath('data.user.name', 'Eve');

        $this->assertSame(1, User::query()->where('email', 'eve@example.com')->count());
    }

    public function test_new_player_has_null_password(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Frank',
                'email' => 'frank@example.com',
            ])
            ->assertCreated();

        $user = User::query()->where('email', 'frank@example.com')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertNull($user->email_verified_at);
    }

    public function test_invitation_stores_only_hash_never_plain_token(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Grace',
                'email' => 'grace@example.com',
            ])
            ->assertCreated();

        $plainToken = (string) $response->json('data.plain_token');
        $this->assertNotSame('', $plainToken);

        $invitation = UserInvitation::query()
            ->where('id', $response->json('data.invitation.id'))
            ->firstOrFail();

        // token_hash must be sha256 hex of the plain token
        $this->assertSame(hash('sha256', $plainToken), $invitation->token_hash);
        // plain token must NOT be stored in the DB
        $this->assertDatabaseMissing('user_invitations', ['token_hash' => $plainToken]);
        // token_hash format: 64 lowercase hex chars
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $invitation->token_hash);
    }

    public function test_invitation_expiration_matches_configured_ttl(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Henry',
                'email' => 'henry@example.com',
            ])
            ->assertCreated();

        $invitation = UserInvitation::query()
            ->where('id', $response->json('data.invitation.id'))
            ->firstOrFail();

        $ttlDays = CreatePlayerInvitationAction::INVITATION_TTL_DAYS;
        $this->assertTrue(
            $invitation->expires_at->between(
                now()->addDays($ttlDays)->subMinutes(2),
                now()->addDays($ttlDays)->addMinutes(2),
            )
        );
    }

    public function test_reinviting_revokes_previous_active_invitation(): void
    {
        $admin = User::factory()->admin()->create();
        $player = User::factory()->unverified()->create(['password' => null]);

        $first = UserInvitation::factory()->create([
            'user_id' => $player->id,
            'invited_by_user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => $player->name,
                'email' => $player->email,
            ])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'reinvited');

        $first->refresh();
        $this->assertNotNull($first->revoked_at);
        $this->assertNull($first->consumed_at);

        // New invitation is active
        $newInvitationId = $response->json('data.invitation.id');
        $this->assertNotSame($first->id, $newInvitationId);

        $newInvitation = UserInvitation::query()->where('id', $newInvitationId)->firstOrFail();
        $this->assertTrue($newInvitation->isActive());

        // Only one active invitation per user
        $this->assertSame(1, UserInvitation::query()
            ->where('user_id', $player->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->count());
    }

    public function test_user_already_registered_with_password_returns_stable_outcome(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'registered@example.com']);

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Registered',
                'email' => 'registered@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'already_registered');

        $this->assertNull($response->json('data.invitation'));
        $this->assertNull($response->json('data.plain_token'));

        // No invitation created
        $user = User::query()->where('email', 'registered@example.com')->firstOrFail();
        $this->assertSame(0, UserInvitation::query()->where('user_id', $user->id)->count());
    }

    public function test_existing_admin_account_returns_already_registered_and_is_not_converted(): void
    {
        $admin = User::factory()->admin()->create();
        $targetAdmin = User::factory()->admin()->create(['email' => 'otheradmin@example.com']);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Other Admin',
                'email' => 'otheradmin@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'already_registered');

        // Role must not change
        $targetAdmin->refresh();
        $this->assertSame(UserRole::Admin, $targetAdmin->role);

        // No invitation created
        $this->assertSame(0, UserInvitation::query()->where('user_id', $targetAdmin->id)->count());
    }

    // ─── Response security ────────────────────────────────────────────────────

    public function test_response_does_not_expose_token_hash_or_password(): void
    {
        $admin = User::factory()->admin()->create();

        $body = $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Ivy',
                'email' => 'ivy@example.com',
            ])
            ->assertCreated()
            ->json();

        $json = json_encode($body, JSON_THROW_ON_ERROR);

        foreach (['token_hash', 'password', 'remember_token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_plain_token_is_not_stored_in_database(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Jack',
                'email' => 'jack@example.com',
            ])
            ->assertCreated();

        $plainToken = (string) $response->json('data.plain_token');
        $this->assertDatabaseMissing('user_invitations', ['token_hash' => $plainToken]);
    }

    // ─── Logging audit ───────────────────────────────────────────────────────

    public function test_logs_do_not_contain_plain_token(): void
    {
        Log::spy();

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Kate',
                'email' => 'kate@example.com',
            ])
            ->assertCreated();

        $plainToken = (string) $response->json('data.plain_token');

        Log::shouldNotHaveReceived('info', function ($args) use ($plainToken): bool {
            return str_contains(json_encode($args, JSON_THROW_ON_ERROR), $plainToken);
        });
    }

    // ─── Action-level concurrency safeguard ──────────────────────────────────

    public function test_sequential_creation_of_same_email_does_not_duplicate_user(): void
    {
        $admin = User::factory()->admin()->create();

        $action = app(CreatePlayerInvitationAction::class);
        $data = new CreatePlayerData('Sam', 'sam@example.com', $admin->id);

        $result1 = $action->execute($data);
        $result2 = $action->execute($data);

        $this->assertSame(CreatePlayerOutcome::Invited, $result1->outcome);
        $this->assertSame(CreatePlayerOutcome::Reinvited, $result2->outcome);

        // Exactly one user
        $this->assertSame(1, User::query()->where('email', 'sam@example.com')->count());

        // Exactly one active invitation
        $user = User::query()->where('email', 'sam@example.com')->firstOrFail();
        $this->assertSame(1, UserInvitation::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->count());
    }

    // ─── Rate limit ──────────────────────────────────────────────────────────

    public function test_create_player_rate_limit_returns_stable_response(): void
    {
        $admin = User::factory()->admin()->create();

        for ($i = 1; $i <= 20; $i++) {
            $this->actingAs($admin)
                ->postJson('/api/v1/admin/players', [
                    'name' => "Player {$i}",
                    'email' => "player{$i}@rl.com",
                ]);
        }

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [
                'name' => 'Over Limit',
                'email' => 'overlimit@rl.com',
            ])
            ->assertTooManyRequests()
            ->assertJsonPath('error', 'too_many_requests');
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    public function test_validation_requires_name_and_email(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/players', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);
    }
}
