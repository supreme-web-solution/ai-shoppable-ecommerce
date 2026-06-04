<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TeamInvite;
use App\Services\Teams\TeamInviteService;
use Illuminate\Http\Request;

class TeamInviteAcceptController extends Controller
{
    public function __construct(private TeamInviteService $invites) {}

    public function show(string $token)
    {
        $invite = $this->findInvite($token);

        return response()->json([
            'team' => [
                'id' => $invite->team->id,
                'name' => $invite->team->name,
                'slug' => $invite->team->slug,
            ],
            'email' => $invite->email,
            'role' => $invite->role,
            'expires_at' => $invite->expires_at,
            'invited_by' => $invite->inviter ? [
                'name' => $invite->inviter->name,
            ] : null,
            'status' => $invite->isPending() ? 'pending' : ($invite->isExpired() ? 'expired' : 'accepted'),
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $invite = $this->findInvite($token);
        $team = $this->invites->accept($invite, $request->user());

        return response()->json([
            'team_id' => $team->id,
            'team_name' => $team->name,
            'message' => 'You have joined '.$team->name.'.',
        ]);
    }

    protected function findInvite(string $token): TeamInvite
    {
        return TeamInvite::query()
            ->where('token', $token)
            ->with(['team', 'inviter:id,name,email'])
            ->firstOrFail();
    }
}
