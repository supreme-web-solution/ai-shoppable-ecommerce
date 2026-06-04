<?php

namespace App\Http\Controllers;

use App\Models\TeamInvite;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TeamInvitePageController extends Controller
{
    public function show(Request $request, string $token)
    {
        $invite = TeamInvite::query()
            ->where('token', $token)
            ->with(['team', 'inviter:id,name'])
            ->firstOrFail();

        $status = $invite->accepted_at
            ? 'accepted'
            : ($invite->expires_at->isPast() ? 'expired' : 'pending');

        return Inertia::render('teams/InviteAccept', [
            'token' => $token,
            'teamName' => $invite->team->name,
            'email' => $invite->email,
            'role' => $invite->role,
            'inviterName' => $invite->inviter?->name,
            'status' => $status,
            'expiresAt' => $invite->expires_at?->toIso8601String(),
        ]);
    }
}
