<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\CommentCreated;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CommentResource;
use App\Models\Comment;
use App\Models\Video;
use App\Support\SafeBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveVideoChatController extends Controller
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

    public function index(Request $request): JsonResponse
    {
        $teamId = $this->resolveTeamId($request);

        $videos = Video::query()
            ->where('team_id', $teamId)
            ->whereHas('comments')
            ->withCount('comments')
            ->latest('id')
            ->get();

        $conversations = $videos->map(function (Video $video): array {
            $lastComment = Comment::query()
                ->where('video_id', $video->id)
                ->latest('id')
                ->first();

            $metadata = is_array($video->metadata) ? $video->metadata : [];

            return [
                'video_id' => $video->id,
                'title' => $video->title,
                'last_message' => $lastComment?->body,
                'last_message_at' => $lastComment?->created_at,
                'messages_count' => (int) $video->comments_count,
                'ai_assistant_enabled' => (bool) data_get($metadata, 'ai_assistant_enabled', false),
            ];
        })->values();

        return response()->json(['data' => $conversations]);
    }

    public function messages(Request $request, Video $video): JsonResponse
    {
        $this->authorize('view', $video);
        $this->resolveTeamId($request);

        $messages = Comment::query()
            ->where('video_id', $video->id)
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(function (Comment $comment): array {
                $metadata = is_array($comment->metadata) ? $comment->metadata : [];
                $senderType = (string) data_get($metadata, 'sender_type', 'attendee');

                return [
                    'id' => $comment->id,
                    'sender_type' => $senderType,
                    'sender_name' => (string) data_get(
                        $metadata,
                        'sender_name',
                        $senderType === 'host' ? 'Host' : ($senderType === 'ai' ? 'AI Assistant' : 'Viewer'),
                    ),
                    'message' => $comment->body,
                    'is_pinned' => (bool) $comment->is_pinned,
                    'created_at' => $comment->created_at,
                ];
            })
            ->values();

        return response()->json(['data' => $messages]);
    }

    public function postMessage(Request $request, Video $video): JsonResponse
    {
        $this->authorize('update', $video);
        $this->resolveTeamId($request);

        $validated = $request->validate([
            'sender_name' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $comment = Comment::query()->create([
            'team_id' => $video->team_id,
            'video_id' => $video->id,
            'user_id' => $request->user()?->id,
            'body' => trim((string) $validated['message']),
            'metadata' => [
                'source' => 'live_video_chat',
                'sender_type' => 'host',
                'sender_name' => trim((string) ($validated['sender_name'] ?? 'Host')),
            ],
        ]);

        $resource = new CommentResource($comment);
        SafeBroadcast::try(fn () => broadcast(new CommentCreated(
            videoId: $comment->video_id,
            comment: $resource->resolve(),
        ))->toOthers());

        return response()->json([
            'data' => [
                'id' => $comment->id,
                'sender_type' => 'host',
                'sender_name' => (string) data_get($comment->metadata, 'sender_name', 'Host'),
                'message' => $comment->body,
                'is_pinned' => (bool) $comment->is_pinned,
                'created_at' => $comment->created_at,
            ],
        ], 201);
    }
}
