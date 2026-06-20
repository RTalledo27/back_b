<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Users\ChangeUserRoleAction;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class UserRoleAssignmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_mass_assignment_on_create_cannot_set_admin_role(): void
    {
        $user = User::create([
            'name' => 'Eve',
            'email' => 'eve@example.com',
            'password' => 'secret',
            'role' => 'admin',
        ]);

        $this->assertSame(UserRole::Player, $user->refresh()->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_mass_assignment_on_update_cannot_change_role(): void
    {
        $user = User::factory()->create();
        $this->assertSame(UserRole::Player, $user->role);

        $user->update([
            'name' => 'New Name',
            'role' => 'admin',
        ]);

        $fresh = $user->refresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame(UserRole::Player, $fresh->role);
    }

    public function test_change_user_role_action_promotes_to_admin(): void
    {
        $user = User::factory()->create();
        $this->assertSame(UserRole::Player, $user->role);

        (new ChangeUserRoleAction)->execute($user, UserRole::Admin);

        $fresh = $user->refresh();
        $this->assertSame(UserRole::Admin, $fresh->role);
        $this->assertTrue($fresh->isAdmin());
    }

    public function test_change_user_role_action_can_demote_to_player(): void
    {
        $admin = User::factory()->admin()->create();

        (new ChangeUserRoleAction)->execute($admin, UserRole::Player);

        $this->assertSame(UserRole::Player, $admin->refresh()->role);
    }
}
