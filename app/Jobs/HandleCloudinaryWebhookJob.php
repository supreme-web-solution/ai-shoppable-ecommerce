<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class HandleCloudinaryWebhookJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function uniqueId(): string
    {
        $baseKey = implode(':', [
            (string) ($this->payload['public_id'] ?? ''),
            (string) ($this->payload['version'] ?? ''),
            (string) ($this->payload['notification_type'] ?? ''),
            (string) ($this->payload['asset_id'] ?? ''),
        ]);

        if (trim($baseKey, ':') === '') {
            return sha1(json_encode($this->payload) ?: '');
        }

        return $baseKey;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $publicId = (string) ($this->payload['public_id'] ?? '');

        if ($publicId === '') {
            return;
        }

        $video = Video::query()
            ->where('cloudinary_public_id', $publicId)
            ->first();

        if (! $video) {
            return;
        }

        $hasError = ($this->payload['status'] ?? null) === 'failed' || isset($this->payload['error']);

        $metadata = (array) ($video->metadata ?? []);
        $metadata['cloudinary'] = array_merge($metadata['cloudinary'] ?? [], [
            'last_webhook_at' => now()->toIso8601String(),
            'last_notification_type' => $this->payload['notification_type'] ?? null,
            'asset_id' => $this->payload['asset_id'] ?? null,
        ]);

        $video->update([
            'status' => $hasError ? 'failed' : 'ready',
            'playback_url' => $this->payload['secure_url'] ?? $video->playback_url,
            'thumbnail_url' => $this->payload['thumbnail_url'] ?? $video->thumbnail_url,
            'duration_seconds' => (int) ($this->payload['duration'] ?? $video->duration_seconds),
            'metadata' => $metadata,
        ]);
    }
}
