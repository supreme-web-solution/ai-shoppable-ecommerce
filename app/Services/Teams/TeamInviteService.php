<?php

namespace App\Services\Teams;

use App\Mail\TeamInviteMail;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamInviteService
{
    /**
     * @return array{status: string, member?: array<string, mixed>, invite?: array<string, mixed>}
     */
    public function invite(Team $team, User $inviter, string $email, string $role = 'member'): array
    {
        $email = Str::lower(trim($email));

        if ($team->users()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This person is already a member of the team.',
            ]);
        }

        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existingUser) {
            $team->users()->attach($existingUser->id, ['role' => $role]);

            if (! $existingUser->team_id) {
                $existingUser->update(['team_id' => $team->id]);
            }

            return [
                'status' => 'added',
                'member' => $this->formatMember($team, $existingUser),
            ];
        }

        $pending = TeamInvite::query()
            ->where('team_id', $team->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($pending) {
            $pending->update([
                'role' => $role,
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(7),
            ]);

            $invite = $pending->fresh(['team', 'inviter']);
        } else {
            $invite = TeamInvite::query()->create([
                'team_id' => $team->id,
                'email' => $email,
                'role' => $role,
                'token' => Str::random(64),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(7),
            ]);

            $invite->load(['team', 'inviter']);
        }

        Mail::to($email)->send(new TeamInviteMail($invite));

        return [
            'status' => 'invited',
            'invite' => $this->formatInvite($invite),
        ];
    }

    public function accept(TeamInvite $invite, User $user): Team
    {
        if ($invite->accepted_at) {
            throw ValidationException::withMessages([
                'invite' => 'This invitation has already been accepted.',
            ]);
        }

        if ($invite->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'invite' => 'This invitation has expired.',
            ]);
        }

        if (Str::lower($user->email) !== Str::lower($invite->email)) {
            throw ValidationException::withMessages([
                'email' => 'This invitation was sent to a different email address.',
            ]);
        }

        if ($invite->team->users()->whereKey($user->id)->exists()) {
            $invite->update(['accepted_at' => now()]);

            return $invite->team;
        }

        $invite->team->users()->attach($user->id, ['role' => $invite->role]);
        $invite->update(['accepted_at' => now()]);

        if (! $user->team_id) {
            $user->update(['team_id' => $invite->team_id]);
        }

        return $invite->team;
    }

    public function revoke(TeamInvite $invite): void
    {
        if ($invite->accepted_at) {
            throw ValidationException::withMessages([
                'invite' => 'Accepted invitations cannot be revoked.',
            ]);
        }

        $invite->delete();
    }

    public function updateMemberRole(Team $team, User $member, string $role, User $actor): void
    {
        if (! $team->users()->whereKey($member->id)->exists()) {
            throw ValidationException::withMessages([
                'member' => 'User is not a member of this team.',
            ]);
        }

        if ($team->owner_user_id === $member->id && $role !== 'owner') {
            throw ValidationException::withMessages([
                'role' => 'Transfer ownership before changing the owner role.',
            ]);
        }

        if ($role === 'owner') {
            throw ValidationException::withMessages([
                'role' => 'Use ownership transfer to assign the owner role.',
            ]);
        }

        $team->users()->updateExistingPivot($member->id, ['role' => $role]);
    }

    public function removeMember(Team $team, User $member, User $actor): void
    {
        if ($team->owner_user_id === $member->id) {
            throw ValidationException::withMessages([
                'member' => 'The team owner cannot be removed.',
            ]);
        }

        if (! $team->users()->whereKey($member->id)->exists()) {
            throw ValidationException::withMessages([
                'member' => 'User is not a member of this team.',
            ]);
        }

        $team->users()->detach($member->id);

        if ((int) $member->team_id === (int) $team->id) {
            $fallbackTeamId = $member->teams()->value('teams.id');
            $member->update(['team_id' => $fallbackTeamId]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function formatMember(Team $team, User $user): array
    {
        $membership = $team->users()->whereKey($user->id)->first();
        $role = $team->owner_user_id === $user->id
            ? 'owner'
            : ($membership?->pivot?->role ?? 'member');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role,
            'joined_at' => $membership?->pivot?->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatInvite(TeamInvite $invite): array
    {
        return [
            'id' => $invite->id,
            'email' => $invite->email,
            'role' => $invite->role,
            'expires_at' => $invite->expires_at,
            'invited_by' => $invite->inviter ? [
                'id' => $invite->inviter->id,
                'name' => $invite->inviter->name,
            ] : null,
            'created_at' => $invite->created_at,
        ];
    }
}
