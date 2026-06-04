<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\CloudinaryService;
use App\Services\Media\LocalVideoStagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessVideoAssetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        public int $videoId,
        public ?string $localFilePath = null,
    ) {}

    public function handle(CloudinaryService $cloudinaryService, LocalVideoStagingService $staging): void
    {
        $video = Video::query()->find($this->videoId);

        if (! $video) {
            Log::warning('ProcessVideoAssetJob: video not found', ['video_id' => $this->videoId]);

            return;
        }

        Log::info('ProcessVideoAssetJob: started', [
            'video_id' => $this->videoId,
            'local_file_path' => $this->localFilePath,
            'file_exists' => $this->localFilePath ? file_exists($this->localFilePath) : false,
        ]);

        $video->update(['status' => 'processing']);

        if (! $this->localFilePath) {
            Log::info('ProcessVideoAssetJob: no local file, marking ready', ['video_id' => $this->videoId]);
            $video->update(['status' => 'ready']);

            return;
        }

        if (! file_exists($this->localFilePath)) {
            throw new \RuntimeException("Local video file not found: {$this->localFilePath}");
        }

        $uploadStartedAt = microtime(true);

        try {
            $upload = $cloudinaryService->uploadVideo($this->localFilePath, [
                'public_id' => 'video_'.$video->id,
            ]);
        } catch (\Throwable $exception) {
            Log::error('ProcessVideoAssetJob: Cloudinary upload threw', [
                'video_id' => $this->videoId,
                'elapsed_seconds' => round(microtime(true) - $uploadStartedAt, 1),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        Log::info('ProcessVideoAssetJob: Cloudinary upload finished', [
            'video_id' => $this->videoId,
            'elapsed_seconds' => round(microtime(true) - $uploadStartedAt, 1),
        ]);

        Log::info('ProcessVideoAssetJob: Cloudinary upload complete', [
            'video_id' => $this->videoId,
            'public_id' => $upload['public_id'] ?? null,
            'secure_url' => $upload['secure_url'] ?? null,
            'thumbnail_url' => $upload['thumbnail_url'] ?? null,
            'duration' => $upload['duration'] ?? null,
            'used_mock' => $upload['used_mock'] ?? false,
        ]);

        $video->update([
            'status' => 'ready',
            'cloudinary_public_id' => $upload['public_id'] ?? null,
            'playback_url' => $upload['secure_url'] ?? null,
            'thumbnail_url' => $upload['thumbnail_url'] ?? $video->thumbnail_url,
            'duration_seconds' => (int) ($upload['duration'] ?? 0),
        ]);

        $staging->delete($this->localFilePath);
        $staging->deleteForVideo($video->fresh());

        Log::info('ProcessVideoAssetJob: video marked ready', [
            'video_id' => $this->videoId,
            'local_file_removed' => ! is_file($this->localFilePath),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Video::query()->whereKey($this->videoId)->update(['status' => 'failed']);

        Log::error('ProcessVideoAssetJob: failed', [
            'video_id' => $this->videoId,
            'local_file_path' => $this->localFilePath,
            'error' => $exception->getMessage(),
        ]);
    }
}
