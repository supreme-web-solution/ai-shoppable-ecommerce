<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Fortify\CreateNewUser;
use App\Mail\TeamInviteMail;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_list_team_members(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/admin/teams/{$team->id}/members");

        $response->assertOk()
            ->assertJsonPath('members.0.email', $owner->email)
            ->assertJsonPath('members.0.role', 'owner');
    }

    public function test_owner_can_invite_new_email(): void
    {
        Mail::fake();

        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/admin/teams/{$team->id}/invites", [
            'email' => 'collaborator@example.com',
            'role' => 'member',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'invited');

        $this->assertDatabaseHas('team_invites', [
            'team_id' => $team->id,
            'email' => 'collaborator@example.com',
            'role' => 'member',
        ]);

        Mail::assertSent(TeamInviteMail::class);
    }

    public function test_owner_can_add_existing_user_directly(): void
    {
        Mail::fake();

        [$team, $owner] = $this->createTeamWithOwner();
        $member = User::factory()->create(['email' => 'existing@example.com']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/admin/teams/{$team->id}/invites", [
            'email' => 'existing@example.com',
            'role' => 'admin',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'added')
            ->assertJsonPath('member.email', 'existing@example.com');

        $this->assertTrue($team->fresh()->users()->whereKey($member->id)->exists());
        Mail::assertNothingSent();
    }

    public function test_member_cannot_invite_others(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $member = User::factory()->create();
        $team->users()->attach($member->id, ['role' => 'member']);
        Sanctum::actingAs($member);

        $response = $this->postJson("/api/v1/admin/teams/{$team->id}/invites", [
            'email' => 'blocked@example.com',
        ]);

        $response->assertForbidden();
    }

    public function test_authenticated_user_can_accept_invite(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);

        $invite = TeamInvite::query()->create([
            'team_id' => $team->id,
            'email' => 'invitee@example.com',
            'role' => 'member',
            'token' => 'test-invite-token',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($invitee);

        $response = $this->postJson("/api/v1/invites/{$invite->token}/accept");

        $response->assertOk()
            ->assertJsonPath('team_id', $team->id);

        $this->assertNotNull($invite->fresh()->accepted_at);
        $this->assertTrue($team->fresh()->users()->whereKey($invitee->id)->exists());
    }

    public function test_registration_with_invite_token_joins_team_without_creating_store(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();

        $invite = TeamInvite::query()->create([
            'team_id' => $team->id,
            'email' => 'newcollab@example.com',
            'role' => 'admin',
            'token' => 'register-invite-token',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDay(),
        ]);

        $user = app(CreateNewUser::class)->create([
            'name' => 'New Collaborator',
            'email' => 'newcollab@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_token' => $invite->token,
        ]);

        $this->assertSame($team->id, $user->team_id);
        $this->assertTrue($team->fresh()->users()->whereKey($user->id)->wherePivot('role', 'admin')->exists());
        $this->assertSame(1, Team::query()->count());
        $this->assertNotNull($invite->fresh()->accepted_at);
    }

    public function test_owner_can_remove_non_owner_member(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $member = User::factory()->create(['team_id' => $team->id]);
        $team->users()->attach($member->id, ['role' => 'member']);
        Sanctum::actingAs($owner);

        $response = $this->deleteJson("/api/v1/admin/teams/{$team->id}/members/{$member->id}");

        $response->assertNoContent();
        $this->assertFalse($team->fresh()->users()->whereKey($member->id)->exists());
    }

    /**
     * @return array{0: Team, 1: User}
     */
    protected function createTeamWithOwner(): array
    {
        $owner = User::factory()->create();

        $team = Team::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Team '.fake()->unique()->word(),
            'slug' => 'team-'.fake()->unique()->slug(),
            'checkout_mode' => 'hybrid',
            'external_provider' => 'none',
        ]);

        $team->users()->attach($owner->id, ['role' => 'owner']);
        $owner->update(['team_id' => $team->id]);

        return [$team, $owner];
    }
}
