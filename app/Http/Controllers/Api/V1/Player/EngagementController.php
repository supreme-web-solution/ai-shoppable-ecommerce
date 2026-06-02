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
use App\Services\Ai\WebinarAssistantService;
use App\Models\ViewerSession;
use App\Support\CommentQuery;
use App\Support\SafeBroadcast;
use App\Support\TeamApiAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class EngagementController extends Controller
{
    public function broadcastConfig(): JsonResponse
    {
        if (config('broadcasting.default') === 'null') {
            return response()->json(['enabled' => false]);
        }

        $reverb = config('broadcasting.connections.reverb');

        return response()->json([
            'enabled' => true,
            'key' => $reverb['key'] ?? null,
            'host' => $reverb['options']['host'] ?? null,
            'port' => (int) ($reverb['options']['port'] ?? 8080),
            'scheme' => $reverb['options']['scheme'] ?? 'https',
        ]);
    }

    public function comments(Request $request, TeamApiAuthorizer $authorizer): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'video_id' => ['required', 'integer', 'exists:videos,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $authorizer->assertPlayerAccess($request, $validated['team_id']);

        $teamId = (int) $validated['team_id'];
        $videoId = (int) $validated['video_id'];
        $video = Video::query()->findOrFail($videoId);
        abort_if((int) $video->team_id !== $teamId, 422, 'Video does not belong to team.');

        $limit = (int) ($validated['limit'] ?? 50);

        $comments = CommentQuery::visibleForPlayer($teamId, $videoId)
            ->whereNull('parent_id')
            ->with([
                'replies' => fn ($query) => CommentQuery::applyVisibleForPlayer($query, $teamId, $videoId)
                    ->orderBy('id'),
            ])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => CommentResource::collection($comments)->resolve(),
        ]);
    }

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

    public function comment(
        StoreCommentRequest $request,
        TeamApiAuthorizer $authorizer,
        WebinarAssistantService $assistantService,
    ): JsonResponse
    {
        $authorizer->assertPlayerAccess($request, (int) $request->input('team_id'));

        $video = Video::query()->findOrFail((int) $request->input('video_id'));
        abort_if($video->team_id !== (int) $request->input('team_id'), 422, 'Video does not belong to team.');

        $requestMetadata = is_array($request->input('metadata')) ? $request->input('metadata') : [];
        $attendeeName = trim((string) data_get($requestMetadata, 'sender_name', 'Viewer'));
        $sessionKey = trim((string) ($request->input('session_key') ?? data_get($requestMetadata, 'session_key', '')));
        $metadata = array_merge($requestMetadata, [
            'source' => 'live_video_chat',
            'sender_type' => 'attendee',
            'sender_name' => $attendeeName !== '' ? $attendeeName : 'Viewer',
            'session_key' => $sessionKey !== '' ? $sessionKey : null,
        ]);

        $parentId = $request->input('parent_id');
        if ($parentId) {
            $parent = Comment::query()->findOrFail((int) $parentId);
            abort_unless($parent->video_id === $video->id, 422, 'Parent comment must belong to this video.');
        }

        $comment = Comment::query()->create([
            ...$request->validated(),
            'user_id' => $request->user()?->id,
            'session_key' => $sessionKey !== '' ? $sessionKey : null,
            'metadata' => $metadata,
        ]);

        $resource = new CommentResource($comment->load('replies'));
        $aiReplies = [];

        SafeBroadcast::try(fn () => broadcast(new CommentCreated(
            videoId: $comment->video_id,
            comment: $resource->resolve(),
        ))->toOthers());

        $videoMetadata = is_array($video->metadata) ? $video->metadata : [];
        $aiEnabled = (bool) data_get($videoMetadata, 'ai_assistant_enabled', false);

        if ($aiEnabled) {
            $replyText = $assistantService->buildReplyForVideo(
                video: $video,
                question: (string) $comment->body,
            );

            $aiComment = Comment::query()->create([
                'team_id' => $video->team_id,
                'video_id' => $video->id,
                'user_id' => null,
                'parent_id' => null,
                'body' => $replyText,
                'metadata' => [
                    'source' => 'live_video_chat',
                    'sender_type' => 'ai',
                    'sender_name' => 'AI Assistant',
                ],
            ]);

            $aiResource = new CommentResource($aiComment);
            $aiReplies[] = $aiResource->resolve();

            SafeBroadcast::try(fn () => broadcast(new CommentCreated(
                videoId: $aiComment->video_id,
                comment: $aiResource->resolve(),
            ))->toOthers());
        }

        return response()->json([
            ...$resource->resolve(),
            'ai_replies' => $aiReplies,
        ], 201);
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
