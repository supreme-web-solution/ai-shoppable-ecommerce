<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\CommentCreated;
use App\Events\CommentModerated;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CommentResource;
use App\Models\ChatSessionBan;
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

    public function threads(Request $request, Video $video): JsonResponse
    {
        $this->authorize('view', $video);
        $this->resolveTeamId($request);

        $sessions = Comment::query()
            ->where('video_id', $video->id)
            ->whereNotNull('session_key')
            ->where('session_key', '!=', '')
            ->selectRaw('session_key, MAX(id) as last_id, COUNT(*) as messages_count')
            ->groupBy('session_key')
            ->orderByDesc('last_id')
            ->limit(100)
            ->get();

        $threads = $sessions->map(function ($row) use ($video): array {
            $last = Comment::query()->find($row->last_id);
            $metadata = is_array($last?->metadata) ? $last->metadata : [];

            return [
                'session_key' => $row->session_key,
                'sender_name' => (string) data_get($metadata, 'sender_name', 'Viewer'),
                'last_message' => $last?->body,
                'last_message_at' => $last?->created_at,
                'messages_count' => (int) $row->messages_count,
                'is_banned' => ChatSessionBan::query()
                    ->where('video_id', $video->id)
                    ->where('session_key', $row->session_key)
                    ->exists(),
            ];
        })->values();

        return response()->json(['data' => $threads]);
    }

    public function messages(Request $request, Video $video): JsonResponse
    {
        $this->authorize('view', $video);
        $this->resolveTeamId($request);

        $validated = $request->validate([
            'session_key' => ['nullable', 'string', 'max:255'],
            'include_hidden' => ['nullable', 'boolean'],
        ]);

        $query = Comment::query()
            ->where('video_id', $video->id)
            ->with(['replies' => fn ($q) => $q->orderBy('id')])
            ->orderBy('id');

        if (! ($validated['include_hidden'] ?? false)) {
            $query->where('is_hidden', false);
        }

        if (! empty($validated['session_key'])) {
            $query->where('session_key', $validated['session_key']);
        }

        $messages = $query
            ->whereNull('parent_id')
            ->limit(500)
            ->get()
            ->map(fn (Comment $comment) => $this->formatAdminMessage($comment))
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
            'parent_id' => ['nullable', 'integer', 'exists:comments,id'],
        ]);

        if (! empty($validated['parent_id'])) {
            $parent = Comment::query()->findOrFail($validated['parent_id']);
            abort_unless($parent->video_id === $video->id, 422, 'Parent comment must belong to this video.');
        }

        $comment = Comment::query()->create([
            'team_id' => $video->team_id,
            'video_id' => $video->id,
            'user_id' => $request->user()?->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'body' => trim((string) $validated['message']),
            'metadata' => [
                'source' => 'live_video_chat',
                'sender_type' => 'host',
                'sender_name' => trim((string) ($validated['sender_name'] ?? 'Host')),
            ],
        ]);

        $resource = new CommentResource($comment->load('replies'));
        SafeBroadcast::try(fn () => broadcast(new CommentCreated(
            videoId: $comment->video_id,
            comment: $resource->resolve(),
        ))->toOthers());

        return response()->json([
            'data' => $this->formatAdminMessage($comment),
        ], 201);
    }

    public function hideComment(Request $request, Video $video, Comment $comment): JsonResponse
    {
        $this->authorize('update', $video);
        $this->resolveTeamId($request);
        abort_unless($comment->video_id === $video->id, 404);

        $comment->update(['is_hidden' => true]);

        SafeBroadcast::try(fn () => broadcast(new CommentModerated(
            videoId: $video->id,
            commentId: $comment->id,
            action: 'hidden',
            sessionKey: $comment->session_key,
        ))->toOthers());

        return response()->json(['ok' => true]);
    }

    public function deleteComment(Request $request, Video $video, Comment $comment): JsonResponse
    {
        $this->authorize('update', $video);
        $this->resolveTeamId($request);
        abort_unless($comment->video_id === $video->id, 404);

        $commentId = $comment->id;
        $sessionKey = $comment->session_key;
        $comment->delete();

        SafeBroadcast::try(fn () => broadcast(new CommentModerated(
            videoId: $video->id,
            commentId: $commentId,
            action: 'deleted',
            sessionKey: $sessionKey,
        ))->toOthers());

        return response()->json(['ok' => true]);
    }

    public function banSession(Request $request, Video $video): JsonResponse
    {
        $this->authorize('update', $video);
        $teamId = $this->resolveTeamId($request);

        $validated = $request->validate([
            'session_key' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        ChatSessionBan::query()->updateOrCreate(
            [
                'video_id' => $video->id,
                'session_key' => $validated['session_key'],
            ],
            [
                'team_id' => $teamId,
                'banned_by_user_id' => $request->user()?->id,
                'reason' => $validated['reason'] ?? null,
            ],
        );

        Comment::query()
            ->where('video_id', $video->id)
            ->where('session_key', $validated['session_key'])
            ->update(['is_hidden' => true]);

        SafeBroadcast::try(fn () => broadcast(new CommentModerated(
            videoId: $video->id,
            commentId: 0,
            action: 'banned',
            sessionKey: $validated['session_key'],
        ))->toOthers());

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatAdminMessage(Comment $comment): array
    {
        $metadata = is_array($comment->metadata) ? $comment->metadata : [];
        $senderType = (string) data_get($metadata, 'sender_type', 'attendee');

        return [
            'id' => $comment->id,
            'parent_id' => $comment->parent_id,
            'session_key' => $comment->session_key,
            'sender_type' => $senderType,
            'sender_name' => (string) data_get(
                $metadata,
                'sender_name',
                $senderType === 'host' ? 'Host' : ($senderType === 'ai' ? 'AI Assistant' : 'Viewer'),
            ),
            'message' => $comment->body,
            'is_pinned' => (bool) $comment->is_pinned,
            'is_hidden' => (bool) $comment->is_hidden,
            'created_at' => $comment->created_at,
            'replies' => $comment->relationLoaded('replies')
                ? $comment->replies->map(fn (Comment $reply) => $this->formatAdminMessage($reply))->values()->all()
                : [],
        ];
    }
}
