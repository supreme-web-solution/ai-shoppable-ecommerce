<?php

namespace App\Http\Controllers\Api\V1\Player;

use App\Events\CommentCreated;
use App\Events\ReactionUpdated;
use App\Events\ViewerCountUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCommentRequest;
use App\Http\Resources\Api\V1\CommentResource;
use App\Models\Comment;
use App\Models\Reaction;
use App\Models\Video;
use App\Models\ViewerSession;
use App\Support\SafeBroadcast;
use App\Support\TeamApiAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class EngagementController extends Controller
{
    public function react(Request $request, TeamApiAuthorizer $authorizer): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'video_id' => ['required', 'integer', 'exists:videos,id'],
            'emoji' => ['required', 'string', 'max:32'],
            'session_id' => ['nullable', 'string', 'max:255'],
        ]);
        $authorizer->assertPlayerAccess($request, $validated['team_id']);

        $video = Video::query()->findOrFail($validated['video_id']);
        abort_if($video->team_id !== $validated['team_id'], 422, 'Video does not belong to team.');

        $reaction = Reaction::query()->updateOrCreate(
            [
                'team_id' => $validated['team_id'],
                'video_id' => $validated['video_id'],
                'session_id' => $validated['session_id'] ?? $request->ip(),
                'emoji' => $validated['emoji'],
            ],
            [
                'user_id' => $request->user()?->id,
                'quantity' => 1,
                'reacted_at' => now(),
            ],
        );

        $counterKey = "live:{$validated['team_id']}:{$validated['video_id']}:reactions";
        $count = (int) Redis::incr($counterKey);
        Redis::expire($counterKey, 7200);

        SafeBroadcast::try(fn () => broadcast(new ReactionUpdated(
            teamId: $validated['team_id'],
            videoId: $validated['video_id'],
            emoji: $validated['emoji'],
            count: $count,
        ))->toOthers());

        return response()->json(['ok' => true, 'reaction_id' => $reaction->id, 'count' => $count], 201);
    }

    public function comment(StoreCommentRequest $request, TeamApiAuthorizer $authorizer): JsonResponse
    {
        $authorizer->assertPlayerAccess($request, (int) $request->input('team_id'));

        $video = Video::query()->findOrFail((int) $request->input('video_id'));
        abort_if($video->team_id !== (int) $request->input('team_id'), 422, 'Video does not belong to team.');

        $comment = Comment::query()->create([
            ...$request->validated(),
            'user_id' => $request->user()?->id,
        ]);

        $resource = new CommentResource($comment->load('replies'));

        SafeBroadcast::try(fn () => broadcast(new CommentCreated(
            videoId: $comment->video_id,
            comment: $resource->resolve(),
        ))->toOthers());

        return response()->json($resource, 201);
    }

    public function viewerPing(Request $request, TeamApiAuthorizer $authorizer): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'video_id' => ['required', 'integer', 'exists:videos,id'],
            'session_key' => ['required', 'string', 'max:255'],
        ]);
        $authorizer->assertPlayerAccess($request, $validated['team_id']);

        $video = Video::query()->findOrFail($validated['video_id']);
        abort_if($video->team_id !== $validated['team_id'], 422, 'Video does not belong to team.');

        $viewerSession = ViewerSession::query()->updateOrCreate(
            [
                'team_id' => $validated['team_id'],
                'video_id' => $validated['video_id'],
                'session_key' => $validated['session_key'],
            ],
            [
                'user_id' => $request->user()?->id,
                'started_at' => now(),
                'last_seen_at' => now(),
                'ended_at' => null,
            ],
        );

        $viewerCount = ViewerSession::query()
            ->where('team_id', $validated['team_id'])
            ->where('video_id', $validated['video_id'])
            ->where('last_seen_at', '>=', now()->subMinutes(2))
            ->count();

        SafeBroadcast::try(fn () => broadcast(new ViewerCountUpdated(
            videoId: $validated['video_id'],
            viewerCount: $viewerCount,
        ))->toOthers());

        return response()->json([
            'ok' => true,
            'viewer_session_id' => $viewerSession->id,
            'viewer_count' => $viewerCount,
        ]);
    }
}
