<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreVideoRequest;
use App\Http\Resources\Api\V1\VideoResource;
use App\Jobs\ProcessVideoAssetJob;
use App\Jobs\RefreshKnowledgeEmbeddingsJob;
use App\Models\Video;
use App\Services\CloudinaryService;
use App\Services\Media\LocalVideoStagingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoController extends Controller
{
    protected function resolveTeamId(Request $request): int
    {
        $teamId = $request->integer('team_id', $request->user()->team_id);
        abort_unless($request->user()->team_id === $teamId || $request->user()->teams()->whereKey($teamId)->exists(), 403);

        return $teamId;
    }

    /**
     * @throws AuthorizationException
     */
    protected function assertBelongsToCurrentTeam(Request $request, int $teamId): void
    {
        abort_unless($request->user()->team_id === $teamId || $request->user()->teams()->whereKey($teamId)->exists(), 403);
    }

    public function index(Request $request)
    {
        $teamId = $this->resolveTeamId($request);

        $videos = Video::query()
            ->where('team_id', $teamId)
            ->with(['productTags.product'])
            ->latest()
            ->paginate(15);

        return VideoResource::collection($videos);
    }

    public function uploadParams(Request $request, CloudinaryService $cloudinaryService)
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
        ]);

        $this->assertBelongsToCurrentTeam($request, (int) $validated['team_id']);

        try {
            return response()->json(
                $cloudinaryService->signedVideoUploadParams('video_'.Str::uuid()->toString()),
            );
        } catch (\Throwable $exception) {
            Log::warning('Video direct upload unavailable, falling back to server upload', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'direct_upload' => false,
            ]);
        }
    }

    public function upload(Request $request)
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'file' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-msvideo', 'max:512000'],
        ]);

        $this->assertBelongsToCurrentTeam($request, (int) $validated['team_id']);

        $storedPath = $request->file('file')->store('uploads/videos', 'local');
        $absolutePath = Storage::disk('local')->path($storedPath);

        Log::info('Video file stored locally', [
            'path' => $absolutePath,
            'original_name' => $request->file('file')->getClientOriginalName(),
            'size_bytes' => $request->file('file')->getSize(),
        ]);

        return response()->json([
            'local_file_path' => $absolutePath,
            'original_name' => $request->file('file')->getClientOriginalName(),
        ]);
    }

    public function store(StoreVideoRequest $request)
    {
        $this->assertBelongsToCurrentTeam($request, (int) $request->input('team_id'));

        $data = $request->validated();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = $this->normalizeVideoMetadata($data['metadata']);
        }

        if (! empty($data['local_file_path'])) {
            $data['status'] = 'processing';
        } elseif (! empty($data['cloudinary_public_id']) && ! empty($data['playback_url'])) {
            $data['status'] = 'ready';
        }

        $video = Video::query()->create([
            ...$data,
            'creator_user_id' => $request->user()?->id,
        ]);

        if ($request->filled('local_file_path')) {
            $localPath = (string) $request->input('local_file_path');
            app(LocalVideoStagingService::class)->rememberForVideo($video, $localPath);
            $this->dispatchVideoProcessing($video->id, $localPath);
        }

        RefreshKnowledgeEmbeddingsJob::dispatch('video', (int) $video->id);

        return new VideoResource($video);
    }

    public function show(Video $video)
    {
        $this->authorize('view', $video);

        return new VideoResource($video->load('productTags.product'));
    }

    public function retryProcessing(Video $video)
    {
        $this->authorize('update', $video);

        if (! in_array($video->status, ['processing', 'failed'], true)) {
            return response()->json([
                'message' => 'This video is not waiting for processing.',
            ], 422);
        }

        $relativePath = (string) data_get($video->metadata, 'local_staging.relative_path', '');

        if ($relativePath === '' || ! Storage::disk('local')->exists($relativePath)) {
            return response()->json([
                'message' => 'The local video file is no longer available. Re-upload the video file.',
            ], 422);
        }

        $localPath = Storage::disk('local')->path($relativePath);

        $video->update([
            'status' => 'processing',
            'playback_url' => null,
            'cloudinary_public_id' => null,
        ]);

        $this->dispatchVideoProcessing($video->id, $localPath);

        return new VideoResource($video->fresh('productTags.product'));
    }

    public function update(Request $request, Video $video)
    {
        $this->authorize('update', $video);
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'source' => ['sometimes', 'in:uploaded,ai_generated,live_replay'],
            'status' => ['sometimes', 'in:draft,processing,ready,published,failed'],
            'visibility' => ['sometimes', 'in:public,unlisted,private'],
            'playback_url' => ['sometimes', 'nullable', 'url'],
            'thumbnail_url' => ['sometimes', 'nullable', 'url'],
            'duration_seconds' => ['sometimes', 'integer', 'min:0'],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'metadata.ai_assistant_enabled' => ['sometimes', 'boolean'],
            'metadata.knowledge_base_text' => ['sometimes', 'nullable', 'string'],
            'metadata.knowledge_sources' => ['sometimes', 'array', 'max:3'],
            'metadata.knowledge_sources.*.title' => ['required_with:metadata.knowledge_sources', 'string', 'max:255'],
            'metadata.knowledge_sources.*.content' => ['required_with:metadata.knowledge_sources', 'string'],
            'local_file_path' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'cloudinary_public_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $localFilePath = $validated['local_file_path'] ?? null;
        unset($validated['local_file_path']);

        $hasDirectCloudinaryUpload = ! empty($validated['cloudinary_public_id'])
            && ! empty($validated['playback_url']);

        if ($localFilePath) {
            $validated['status'] = 'processing';
            $validated['playback_url'] = null;
            $validated['cloudinary_public_id'] = null;
        } elseif ($hasDirectCloudinaryUpload) {
            $validated['status'] = 'ready';
        }

        if ($localFilePath || $hasDirectCloudinaryUpload) {
            app(LocalVideoStagingService::class)->deleteForVideo($video);
        }

        if (array_key_exists('metadata', $validated) && is_array($validated['metadata'])) {
            $validated['metadata'] = $this->normalizeVideoMetadata($validated['metadata']);
        }

        $video->update($validated);

        if ($localFilePath) {
            app(LocalVideoStagingService::class)->rememberForVideo($video->fresh(), $localFilePath);
            $this->dispatchVideoProcessing($video->id, $localFilePath);
        }

        if (array_key_exists('metadata', $validated)) {
            RefreshKnowledgeEmbeddingsJob::dispatch('video', (int) $video->id);
        }

        return new VideoResource($video->fresh('productTags.product'));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function normalizeVideoMetadata(array $metadata): array
    {
        $sources = collect((array) ($metadata['knowledge_sources'] ?? []))
            ->take(3)
            ->filter(fn (mixed $source): bool => is_array($source))
            ->map(fn (array $source): array => [
                'title' => trim((string) ($source['title'] ?? '')),
                'content' => trim((string) ($source['content'] ?? '')),
            ])
            ->filter(fn (array $source): bool => $source['title'] !== '' && $source['content'] !== '')
            ->values()
            ->all();

        $metadata['ai_assistant_enabled'] = (bool) ($metadata['ai_assistant_enabled'] ?? false);
        $metadata['knowledge_base_text'] = isset($metadata['knowledge_base_text'])
            ? trim((string) $metadata['knowledge_base_text'])
            : null;
        $metadata['knowledge_sources'] = $sources;

        return $metadata;
    }

    protected function dispatchVideoProcessing(int $videoId, string $localFilePath): void
    {
        $queue = config('queue.names.media', 'media');

        ProcessVideoAssetJob::dispatch(
            videoId: $videoId,
            localFilePath: $localFilePath,
        )->onQueue($queue);

        Log::info('Dispatched ProcessVideoAssetJob', [
            'video_id' => $videoId,
            'queue' => $queue,
            'local_file_path' => $localFilePath,
            'file_exists' => file_exists($localFilePath),
        ]);
    }

    public function destroy(Video $video)
    {
        $this->authorize('delete', $video);

        app(LocalVideoStagingService::class)->deleteForVideo($video);
        $video->delete();

        return response()->noContent();
    }
}
