<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use App\Services\Teams\TeamInviteService;
use Illuminate\Http\Request;

class TeamMemberController extends Controller
{
    public function __construct(private TeamInviteService $invites) {}

    public function index(Team $team)
    {
        $this->authorize('view', $team);

        $members = $team->users()
            ->orderBy('users.name')
            ->get()
            ->map(fn (User $user) => $this->invites->formatMember($team, $user))
            ->values();

        $pendingInvites = TeamInvite::query()
            ->where('team_id', $team->id)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with('inviter:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TeamInvite $invite) => $this->invites->formatInvite($invite))
            ->values();

        return response()->json([
            'members' => $members,
            'pending_invites' => $pendingInvites,
        ]);
    }

    public function invite(Request $request, Team $team)
    {
        $this->authorize('manageMembers', $team);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['nullable', 'in:admin,member'],
        ]);

        $result = $this->invites->invite(
            $team,
            $request->user(),
            $validated['email'],
            $validated['role'] ?? 'member',
        );

        return response()->json($result, $result['status'] === 'added' ? 200 : 201);
    }

    public function updateMember(Request $request, Team $team, User $user)
    {
        $this->authorize('manageMembers', $team);

        $validated = $request->validate([
            'role' => ['required', 'in:admin,member'],
        ]);

        $this->invites->updateMemberRole($team, $user, $validated['role'], $request->user());

        return response()->json([
            'member' => $this->invites->formatMember($team->fresh(), $user->fresh()),
        ]);
    }

    public function destroyMember(Request $request, Team $team, User $user)
    {
        $this->authorize('manageMembers', $team);

        $this->invites->removeMember($team, $user, $request->user());

        return response()->noContent();
    }

    public function destroyInvite(Team $team, TeamInvite $invite)
    {
        $this->authorize('manageMembers', $team);

        abort_unless((int) $invite->team_id === (int) $team->id, 404);

        $this->invites->revoke($invite);

        return response()->noContent();
    }
}
