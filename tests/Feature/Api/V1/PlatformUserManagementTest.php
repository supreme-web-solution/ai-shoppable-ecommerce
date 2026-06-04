<?php

namespace Tests\Feature\Api\V1;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.emails' => ['admin@example.com']]);
    }

    public function test_non_admin_cannot_list_platform_users(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);

        $this->actingAs($user)
            ->getJson('/api/v1/admin/platform/users')
            ->assertForbidden();
    }

    public function test_platform_admin_can_search_users_by_name_or_email(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        User::factory()->create(['name' => 'Alice Unique', 'email' => 'alice@example.com']);
        User::factory()->create(['name' => 'Bob Other', 'email' => 'bob@example.com']);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/platform/users?search=Alice')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'alice@example.com');

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/platform/users?search=bob@example.com')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bob Other');
    }

    public function test_platform_admin_can_create_list_update_and_delete_users(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/platform/users', [
                'name' => 'New Merchant',
                'email' => 'merchant@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'mark_verified' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'merchant@example.com');

        $created = User::query()->where('email', 'merchant@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/platform/users?search=merchant')
            ->assertOk()
            ->assertJsonPath('data.0.id', $created->id);

        $team = Team::query()->create([
            'owner_user_id' => $admin->id,
            'name' => 'Other Store',
            'slug' => 'other-store',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/platform/users/{$created->id}", [
                'name' => 'Updated Merchant',
                'team_id' => $team->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Merchant')
            ->assertJsonPath('data.current_team.id', $team->id);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/platform/users/{$created->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $created->id]);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/platform/users/{$admin->id}")
            ->assertStatus(422);
    }

    public function test_admin_users_page_requires_platform_admin(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);

        $this->actingAs($user)
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_admin_users_page_is_accessible_to_platform_admin(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk();
    }
}
