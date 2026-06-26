<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserInvitation;
use App\Models\UserSocialAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IdentityPersistenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_password_may_be_null_for_optional_local_credentials(): void
    {
        $user = User::create([
            'name' => 'Social Only',
            'email' => 'social-only@example.com',
            'password' => null,
        ]);

        $fresh = $user->refresh();

        $this->assertNull($fresh->password);
        $this->assertSame(UserRole::Player, $fresh->role);
        $this->assertFalse($fresh->isAdmin());
    }

    public function test_user_social_account_uses_uuid_v7_and_can_belong_to_passwordless_user(): void
    {
        $user = User::factory()->create(['password' => null]);

        $account = UserSocialAccount::factory()
            ->for($user)
            ->create([
                'provider' => 'google',
                'provider_user_id' => 'google-stable-id',
            ]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $account->id,
        );
        $this->assertTrue($account->user->is($user));
        $this->assertSame('google-stable-id', $user->socialAccounts()->firstOrFail()->provider_user_id);
    }

    public function test_provider_identity_is_unique_per_provider(): void
    {
        UserSocialAccount::factory()->create([
            'provider' => 'google',
            'provider_user_id' => 'same-provider-id',
        ]);

        $this->expectException(QueryException::class);

        UserSocialAccount::factory()->create([
            'provider' => 'google',
            'provider_user_id' => 'same-provider-id',
        ]);
    }

    public function test_user_can_have_only_one_account_per_provider(): void
    {
        $user = User::factory()->create();

        UserSocialAccount::factory()
            ->for($user)
            ->create(['provider' => 'facebook']);

        $this->expectException(QueryException::class);

        UserSocialAccount::factory()
            ->for($user)
            ->create(['provider' => 'facebook']);
    }

    public function test_social_provider_is_limited_to_google_and_facebook(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('user_social_accounts')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'provider' => 'github',
            'provider_user_id' => 'external-id',
            'provider_email' => 'provider@example.com',
            'provider_email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_user_invitation_uses_uuid_v7_and_hides_token_hash_from_serialization(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $invitation = UserInvitation::factory()
            ->for($user)
            ->create(['invited_by_user_id' => $admin->id]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $invitation->id,
        );
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertTrue($invitation->user->is($user));
        $this->assertTrue($invitation->invitedBy->is($admin));
        $this->assertTrue($invitation->isActive());
        $this->assertTrue($invitation->isValidForActivation(now()));
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());
    }

    public function test_invitation_token_hash_is_unique(): void
    {
        $tokenHash = hash('sha256', 'activation-token');

        UserInvitation::factory()->create(['token_hash' => $tokenHash]);

        $this->expectException(QueryException::class);

        UserInvitation::factory()->create(['token_hash' => $tokenHash]);
    }

    public function test_expired_invitation_is_not_valid_for_activation(): void
    {
        $invitation = UserInvitation::factory()
            ->expired()
            ->create();

        $this->assertTrue($invitation->isExpired(now()));
        $this->assertTrue($invitation->isActive());
        $this->assertFalse($invitation->isValidForActivation(now()));
        $this->assertFalse($invitation->canBeConsumed(now()));
        $this->assertTrue($invitation->canBeRevoked());
    }

    public function test_revoked_invitation_is_not_active(): void
    {
        $invitation = UserInvitation::factory()
            ->revoked()
            ->create();

        $this->assertTrue($invitation->isRevoked());
        $this->assertFalse($invitation->isConsumed());
        $this->assertFalse($invitation->isActive());
        $this->assertFalse($invitation->isValidForActivation(now()));
        $this->assertFalse($invitation->canBeConsumed(now()));
        $this->assertFalse($invitation->canBeRevoked());
    }

    public function test_consumed_invitation_cannot_be_revoked(): void
    {
        $invitation = UserInvitation::factory()
            ->consumed()
            ->create();

        $this->assertTrue($invitation->isConsumed());
        $this->assertFalse($invitation->isRevoked());
        $this->assertFalse($invitation->isActive());
        $this->assertFalse($invitation->canBeRevoked());
    }

    public function test_user_can_have_only_one_active_invitation(): void
    {
        $user = User::factory()->create();

        UserInvitation::factory()
            ->for($user)
            ->create(['consumed_at' => null, 'revoked_at' => null]);

        $this->expectException(QueryException::class);

        UserInvitation::factory()
            ->for($user)
            ->create(['consumed_at' => null, 'revoked_at' => null]);
    }

    public function test_consumed_invitation_does_not_block_new_active_invitation(): void
    {
        $user = User::factory()->create();

        UserInvitation::factory()
            ->for($user)
            ->consumed()
            ->create();

        $pending = UserInvitation::factory()
            ->for($user)
            ->create(['consumed_at' => null, 'revoked_at' => null]);

        $this->assertNull($pending->consumed_at);
        $this->assertNull($pending->revoked_at);
        $this->assertSame(2, $user->invitations()->count());
    }

    public function test_revoked_invitation_does_not_block_new_active_invitation(): void
    {
        $user = User::factory()->create();

        UserInvitation::factory()
            ->for($user)
            ->revoked()
            ->create();

        $active = UserInvitation::factory()
            ->for($user)
            ->create(['consumed_at' => null, 'revoked_at' => null]);

        $this->assertTrue($active->isActive());
        $this->assertSame(2, $user->invitations()->count());
    }

    public function test_invitation_cannot_be_consumed_and_revoked_at_database_level(): void
    {
        $this->expectException(QueryException::class);

        UserInvitation::factory()->create([
            'consumed_at' => now(),
            'revoked_at' => now(),
        ]);
    }
}
