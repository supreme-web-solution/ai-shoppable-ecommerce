<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\Video;
use App\Services\Integrations\ZernioService;
use App\Services\Social\SocialAccountConnectionService;
use App\Services\Social\SocialPublishService;
use App\Services\Social\SocialShopLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZernioController extends Controller
{
    public function __construct(
        protected ZernioService $zernio,
        protected SocialShopLinkService $shopLinks,
        protected SocialAccountConnectionService $connections,
        protected SocialPublishService $publisher,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $this->assertEnabled();

        $team = $this->resolveTeam($request);
        $profileId = trim((string) data_get($this->zernio->zernioSettings($team), 'profile_id', ''));

        return response()->json([
            'enabled' => true,
            'profile_id' => $profileId !== '' ? $profileId : null,
            'accounts' => $this->connections->listForTeam($team),
            'supported_platforms' => $this->zernio->supportedPlatforms(),
            'publish_limits' => $this->publisher->publishLimits(),
        ]);
    }

    public function ensureProfile(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $team = $this->resolveTeam($request);
        $profileId = $this->zernio->ensureTeamProfile($team);

        return response()->json([
            'profile_id' => $profileId,
        ]);
    }

    public function connectUrl(Request $request): JsonResponse
    {
        $this->assertEnabled();

        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'platform' => ['required', 'string', 'max:40'],
        ]);

        $team = $this->resolveTeam($request);
        abort_unless((int) $validated['team_id'] === $team->id, 422);

        $url = $this->zernio->connectUrl($team, $validated['platform']);

        return response()->json(['auth_url' => $url]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $team = $this->resolveTeam($request);

        return response()->json([
            'accounts' => $this->connections->listForTeam($team),
        ]);
    }

    public function disconnectAccount(Request $request, string $accountId): JsonResponse
    {
        $this->assertEnabled();
        $team = $this->resolveTeam($request);

        $this->connections->disconnectByAccountId($team, $accountId);

        return response()->json([
            'disconnected' => true,
            'accounts' => $this->connections->listForTeam($team),
        ]);
    }

    public function disconnectPlatform(Request $request, string $platform): JsonResponse
    {
        $this->assertEnabled();
        $team = $this->resolveTeam($request);

        $this->connections->disconnect($team, $platform);

        return response()->json([
            'disconnected' => true,
            'accounts' => $this->connections->listForTeam($team),
        ]);
    }

    public function shopLink(Request $request): JsonResponse
    {
        $this->assertEnabled();

        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'video_id' => ['nullable', 'integer', 'exists:videos,id'],
            'playlist_id' => ['nullable', 'integer', 'exists:playlists,id'],
        ]);

        abort_if(
            empty($validated['video_id']) && empty($validated['playlist_id']),
            422,
            'Provide video_id or playlist_id.'
        );

        $team = $this->resolveTeam($request);
        abort_unless((int) $validated['team_id'] === $team->id, 422);

        if (! empty($validated['playlist_id'])) {
            $playlist = Playlist::query()->findOrFail($validated['playlist_id']);
            $this->assertPlaylistShareable($playlist);
            $embed = $this->shopLinks->ensureShopEmbedForPlaylist($team, $playlist);

            return response()->json([
                'shop_url' => $this->shopLinks->shopUrlForEmbed($embed),
                'embed_slug' => $embed->slug,
                'type' => 'playlist',
            ]);
        }

        $video = Video::query()->findOrFail($validated['video_id']);
        $this->assertVideoPublished($video);
        $embed = $this->shopLinks->ensureShopEmbedForVideo($team, $video);

        return response()->json([
            'shop_url' => $this->shopLinks->shopUrlForEmbed($embed),
            'embed_slug' => $embed->slug,
            'type' => 'video',
        ]);
    }

    public function publish(Request $request): JsonResponse
    {
        $this->assertEnabled();

        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'video_id' => ['nullable', 'integer', 'exists:videos,id'],
            'playlist_id' => ['nullable', 'integer', 'exists:playlists,id'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'publish_now' => ['sometimes', 'boolean'],
            'scheduled_for' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*.platform' => ['required', 'string', 'max:40'],
            'platforms.*.accountId' => ['required', 'string', 'max:120'],
        ]);

        abort_if(
            empty($validated['video_id']) && empty($validated['playlist_id']),
            422,
            'Provide video_id or playlist_id.'
        );

        $team = $this->resolveTeam($request);
        abort_unless((int) $validated['team_id'] === $team->id, 422);

        $video = null;
        $playlist = null;
        $embed = null;
        $mediaUrls = [];

        if (! empty($validated['playlist_id'])) {
            $playlist = Playlist::query()->with('videos')->findOrFail($validated['playlist_id']);
            $this->assertPlaylistShareable($playlist);
            $embed = $this->shopLinks->ensureShopEmbedForPlaylist($team, $playlist);
            $firstVideo = $playlist->videos->first();
            if ($firstVideo?->playback_url) {
                $mediaUrls[] = $firstVideo->playback_url;
            }
            $video = $firstVideo;
        } else {
            $video = Video::query()->findOrFail($validated['video_id']);
            $this->assertVideoPublished($video);
            $embed = $this->shopLinks->ensureShopEmbedForVideo($team, $video);
            if ($video->playback_url) {
                $mediaUrls[] = $video->playback_url;
            }
        }

        abort_if($video === null, 422, 'No video available to publish.');

        if ($playlist !== null) {
            $this->assertVideoPublished($video);
        }

        $shopUrl = $this->shopLinks->shopUrlForEmbed($embed);
        $caption = $this->shopLinks->buildCaption($video, $shopUrl, $validated['caption'] ?? null);
        $publishNow = (bool) ($validated['publish_now'] ?? true);
        $hasMedia = count($mediaUrls) > 0;
        $mediaType = $hasMedia ? 'video' : null;

        try {
            $prepared = $this->publisher->prepare(
                $team,
                $caption,
                $validated['platforms'],
                $hasMedia,
                $mediaType,
                $video->title,
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $socialPost = SocialPost::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'playlist_id' => $playlist?->id,
            'embed_id' => $embed->id,
            'status' => 'pending',
            'caption' => $caption,
            'shop_url' => $shopUrl,
            'platforms' => $validated['platforms'],
            'scheduled_for' => $validated['scheduled_for'] ?? null,
        ]);

        try {
            $response = $this->zernio->createPost(
                content: $prepared['content'],
                platforms: $prepared['platforms'],
                scheduledFor: isset($validated['scheduled_for'])
                    ? $validated['scheduled_for']
                    : null,
                timezone: $validated['timezone'] ?? null,
                publishNow: $publishNow,
                mediaUrls: $mediaUrls,
            );

            $zernioPostId = (string) (data_get($response, 'post._id') ?? data_get($response, '_id') ?? '');
            $status = $publishNow ? 'published' : 'scheduled';

            $socialPost->update([
                'zernio_post_id' => $zernioPostId !== '' ? $zernioPostId : null,
                'status' => $status,
                'published_at' => $publishNow ? now() : null,
            ]);

            return response()->json([
                'social_post' => $socialPost->fresh(),
                'zernio' => $response,
                'shop_url' => $shopUrl,
                'adaptations' => $prepared['adaptations'],
            ]);
        } catch (\Throwable $e) {
            $socialPost->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $this->publisher->friendlyErrorMessage($e->getMessage()),
                'social_post' => $socialPost->fresh(),
            ], 422);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $team = $this->resolveTeam($request);

        $posts = SocialPost::query()
            ->where('team_id', $team->id)
            ->when($request->integer('video_id'), fn ($q, $id) => $q->where('video_id', $id))
            ->latest()
            ->limit(20)
            ->get();

        return response()->json(['posts' => $posts]);
    }

    protected function assertEnabled(): void
    {
        abort_unless($this->zernio->enabled(), 404, 'Zernio integration is disabled.');
    }

    protected function resolveTeam(Request $request): Team
    {
        $teamId = (int) $request->integer('team_id');
        $team = Team::query()->findOrFail($teamId);
        $user = $request->user();
        abort_unless($user && $user->teams()->where('teams.id', $team->id)->exists(), 403);

        return $team;
    }

    protected function assertVideoPublished(Video $video): void
    {
        abort_unless(
            $video->status === 'published',
            422,
            'Publish this video before sharing or embedding.'
        );
    }

    protected function assertPlaylistShareable(Playlist $playlist): void
    {
        abort_unless(
            $playlist->is_active && $playlist->is_public,
            422,
            'Make this playlist public before sharing or embedding.'
        );
    }
}
