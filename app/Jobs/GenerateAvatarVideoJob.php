<?php

namespace App\Jobs;

use App\Models\AiGeneration;
use App\Models\Video;
use App\Services\Ai\AiAvatarVideoService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateAvatarVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 900;

    public function __construct(
        public int $generationId,
    ) {}

    public function uniqueId(): string
    {
        return 'avatar-video:'.$this->generationId;
    }

    public function handle(AiAvatarVideoService $avatarVideoService): void
    {
        Log::info('AI avatar video job started', [
            'generation_id' => $this->generationId,
            'queue_connection' => config('queue.default'),
            'ai_queue' => config('queue.names.ai', 'ai'),
            'attempt' => $this->attempts(),
        ]);

        $generation = AiGeneration::query()->find($this->generationId);

        if (! $generation || $generation->type !== 'avatar_video') {
            Log::warning('AI avatar video job skipped because generation was missing or invalid', [
                'generation_id' => $this->generationId,
                'found' => (bool) $generation,
                'type' => $generation?->type,
            ]);

            return;
        }

        $generation->update(['status' => 'processing']);

        Log::info('AI avatar video generation marked processing', [
            'generation_id' => $generation->id,
            'video_id' => $generation->video_id,
            'provider' => $generation->provider,
        ]);

        if ($generation->external_id) {
            $submission = array_merge((array) ($generation->output ?? []), [
                'provider' => $generation->provider,
                'external_id' => $generation->external_id,
                'status' => 'processing',
            ]);

            Log::info('AI avatar video reusing existing provider submission', [
                'generation_id' => $generation->id,
                'video_id' => $generation->video_id,
                'provider' => $generation->provider,
                'external_id' => $generation->external_id,
            ]);
        } else {
            $submission = $avatarVideoService->submit((array) ($generation->input ?? []));
        }

        $generation->update([
            'provider' => (string) ($submission['provider'] ?? 'mock'),
            'external_id' => (string) ($submission['external_id'] ?? ''),
            'output' => $submission,
        ]);

        Log::info('AI avatar video provider submission stored', [
            'generation_id' => $generation->id,
            'video_id' => $generation->video_id,
            'provider' => $generation->provider,
            'external_id' => $generation->external_id,
            'submission_status' => $submission['status'] ?? null,
            'mock' => $submission['mock'] ?? false,
        ]);

        $video = Video::query()->find($generation->video_id);
        if ($video) {
            $video->update([
                'status' => 'processing',
                'metadata' => array_merge((array) ($video->metadata ?? []), [
                    'ai_generation_id' => $generation->id,
                    'avatar_provider' => $generation->provider,
                ]),
            ]);

            Log::info('AI avatar video record updated after submission', [
                'generation_id' => $generation->id,
                'video_id' => $video->id,
                'status' => $video->status,
                'provider' => $generation->provider,
            ]);
        } else {
            Log::warning('AI avatar video job could not find video record', [
                'generation_id' => $generation->id,
                'video_id' => $generation->video_id,
            ]);
        }

        $this->pollUntilComplete($avatarVideoService, $generation, $video);
    }

    protected function pollUntilComplete(
        AiAvatarVideoService $avatarVideoService,
        AiGeneration $generation,
        ?Video $video,
    ): void {
        $attempts = 0;
        $maxAttempts = (int) config('services.ai.avatar_poll_attempts', 60);
        $sleepSeconds = (int) config('services.ai.avatar_poll_sleep_seconds', 10);

        while ($attempts < $maxAttempts) {
            $attempts++;

            Log::info('AI avatar video poll attempt started', [
                'generation_id' => $generation->id,
                'video_id' => $video?->id,
                'provider' => $generation->provider,
                'external_id' => $generation->external_id,
                'attempt' => $attempts,
                'max_attempts' => $maxAttempts,
            ]);

            if ($generation->external_id) {
                $result = $avatarVideoService->poll($generation->provider, $generation->external_id);
            } else {
                $result = null;
            }

            if ($result === null && $generation->provider === 'mock') {
                sleep(2);
                $result = $avatarVideoService->poll('mock', (string) $generation->external_id);
            }

            if ($result === null) {
                Log::warning('AI avatar video poll returned no result; retrying', [
                    'generation_id' => $generation->id,
                    'video_id' => $video?->id,
                    'provider' => $generation->provider,
                    'external_id' => $generation->external_id,
                    'attempt' => $attempts,
                    'sleep_seconds' => $sleepSeconds,
                ]);

                sleep($sleepSeconds);

                continue;
            }

            if (($result['status'] ?? '') === 'processing') {
                Log::info('AI avatar video still processing; retrying', [
                    'generation_id' => $generation->id,
                    'video_id' => $video?->id,
                    'provider' => $generation->provider,
                    'external_id' => $generation->external_id,
                    'attempt' => $attempts,
                    'sleep_seconds' => $sleepSeconds,
                ]);

                sleep($sleepSeconds);

                continue;
            }

            if (($result['status'] ?? '') === 'failed') {
                Log::warning('AI avatar video provider reported failure', [
                    'generation_id' => $generation->id,
                    'video_id' => $video?->id,
                    'provider' => $generation->provider,
                    'external_id' => $generation->external_id,
                    'error_message' => $result['error_message'] ?? 'Avatar provider failed.',
                ]);

                $generation->update([
                    'status' => 'failed',
                    'error_message' => (string) ($result['error_message'] ?? 'Avatar provider failed.'),
                    'completed_at' => now(),
                ]);
                $video?->update(['status' => 'failed']);

                return;
            }

            $generation->update([
                'status' => 'completed',
                'output' => array_merge((array) ($generation->output ?? []), $result),
                'completed_at' => now(),
            ]);

            $video?->update([
                'status' => 'ready',
                'playback_url' => (string) ($result['playback_url'] ?? $video->playback_url),
                'thumbnail_url' => (string) ($result['thumbnail_url'] ?? $video->thumbnail_url),
                'duration_seconds' => (int) ($result['duration_seconds'] ?? $video->duration_seconds),
                'metadata' => array_merge((array) ($video->metadata ?? []), [
                    'ai_completed_at' => now()->toIso8601String(),
                ]),
            ]);

            Log::info('AI avatar video completed successfully', [
                'generation_id' => $generation->id,
                'video_id' => $video?->id,
                'provider' => $generation->provider,
                'external_id' => $generation->external_id,
                'playback_url' => $result['playback_url'] ?? null,
                'thumbnail_url' => $result['thumbnail_url'] ?? null,
                'duration_seconds' => $result['duration_seconds'] ?? null,
            ]);

            return;
        }

        $generation->update([
            'status' => 'failed',
            'error_message' => 'Avatar generation timed out while polling provider.',
            'completed_at' => now(),
        ]);
        $video?->update(['status' => 'failed']);

        Log::warning('Avatar generation polling timed out', [
            'generation_id' => $generation->id,
            'video_id' => $video?->id,
            'provider' => $generation->provider,
            'external_id' => $generation->external_id,
            'max_attempts' => $maxAttempts,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $generation = AiGeneration::query()->find($this->generationId);

        if (! $generation) {
            return;
        }

        $generation->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'completed_at' => now(),
        ]);

        Video::query()->whereKey($generation->video_id)->update(['status' => 'failed']);

        Log::error('AI avatar video job failed', [
            'generation_id' => $generation->id,
            'video_id' => $generation->video_id,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }
}
