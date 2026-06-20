<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unauthenticated_request_to_user_endpoint_returns_401(): void
    {
        $this->getJson('/api/v1/user')->assertStatus(401);
    }

    public function test_unauthenticated_request_to_admin_create_game_returns_401(): void
    {
        $this->postJson('/api/v1/admin/games', [])->assertStatus(401);
    }

    public function test_player_cannot_access_admin_create_game(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Player]));

        // 403 from the EnsureUserIsAdmin middleware (FormRequest::authorize is never reached).
        $this->postJson('/api/v1/admin/games', [])->assertStatus(403);
    }

    public function test_admin_passes_middleware_chain_and_reaches_validation(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        // 422 proves the admin got past auth + admin middleware and hit
        // FormRequest validation with an empty body.
        $this->postJson('/api/v1/admin/games', [])->assertStatus(422);
    }
}
