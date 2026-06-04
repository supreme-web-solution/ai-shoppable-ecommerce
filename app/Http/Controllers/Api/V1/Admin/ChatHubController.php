<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\LiveShowRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatHubController extends Controller
{
    protected function resolveTeamId(Request $request): int
    {
        $teamId = $request->integer('team_id', $request->user()->team_id);
        abort_unless(
            $request->user()->team_id === $teamId
            || $request->user()->teams()->whereKey($teamId)->exists(),
            403,
        );

        return $teamId;
    }

    public function summary(Request $request): JsonResponse
    {
        $teamId = $this->resolveTeamId($request);

        $webinarChatsCount = LiveShowRegistration::query()
            ->whereHas('liveShow', fn ($query) => $query->where('team_id', $teamId))
            ->whereHas('messages')
            ->count();

        $liveVideoChatsCount = Comment::query()
            ->whereHas('video', fn ($query) => $query->where('team_id', $teamId))
            ->whereNotNull('session_key')
            ->where('session_key', '!=', '')
            ->select('video_id', 'session_key')
            ->groupBy('video_id', 'session_key')
            ->get()
            ->count();

        return response()->json([
            'data' => [
                'webinar_chats_count' => $webinarChatsCount,
                'live_video_chats_count' => $liveVideoChatsCount,
                'total_chats_count' => $webinarChatsCount + $liveVideoChatsCount,
            ],
        ]);
    }
}
