<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreVideoRequest;
use App\Http\Resources\Api\V1\VideoResource;
use App\Jobs\ProcessVideoAssetJob;
use App\Models\Video;
use App\Services\Media\LocalVideoStagingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

        if (! empty($data['local_file_path'])) {
            $data['status'] = 'processing';
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

        return new VideoResource($video);
    }

    public function show(Video $video)
    {
        $this->authorize('view', $video);

        return new VideoResource($video->load('productTags.product'));
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
            'local_file_path' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $localFilePath = $validated['local_file_path'] ?? null;
        unset($validated['local_file_path']);

        if ($localFilePath) {
            $validated['status'] = 'processing';
            $validated['playback_url'] = null;
            $validated['cloudinary_public_id'] = null;
        }

        if ($localFilePath) {
            app(LocalVideoStagingService::class)->deleteForVideo($video);
        }

        $video->update($validated);

        if ($localFilePath) {
            app(LocalVideoStagingService::class)->rememberForVideo($video->fresh(), $localFilePath);
            $this->dispatchVideoProcessing($video->id, $localFilePath);
        }

        return new VideoResource($video->fresh('productTags.product'));
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
