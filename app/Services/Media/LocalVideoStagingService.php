<?php

namespace App\Services\Media;

use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LocalVideoStagingService
{
    public const STAGING_DIR = 'uploads/videos';

    public function rememberForVideo(Video $video, string $absoluteOrRelativePath): void
    {
        $relative = $this->toRelativePath($absoluteOrRelativePath);

        if ($relative === null) {
            return;
        }

        $metadata = (array) ($video->metadata ?? []);
        $metadata['local_staging'] = [
            'relative_path' => $relative,
            'stored_at' => now()->toIso8601String(),
        ];

        $video->update(['metadata' => $metadata]);
    }

    public function deleteForVideo(Video $video): bool
    {
        $relative = (string) data_get($video->metadata, 'local_staging.relative_path', '');

        if ($relative === '') {
            return false;
        }

        $deleted = $this->delete($relative);

        if ($deleted) {
            $metadata = (array) ($video->metadata ?? []);
            unset($metadata['local_staging']);
            $video->update(['metadata' => $metadata]);
        }

        return $deleted;
    }

    public function delete(?string $absoluteOrRelativePath): bool
    {
        if ($absoluteOrRelativePath === null || trim($absoluteOrRelativePath) === '') {
            return false;
        }

        $relative = $this->toRelativePath($absoluteOrRelativePath) ?? $absoluteOrRelativePath;
        $disk = Storage::disk('local');

        if ($disk->exists($relative)) {
            $deleted = $disk->delete($relative);

            Log::info('LocalVideoStagingService: deleted staged file', [
                'relative_path' => $relative,
                'deleted' => $deleted,
            ]);

            return $deleted;
        }

        if (is_file($absoluteOrRelativePath)) {
            $deleted = unlink($absoluteOrRelativePath);

            Log::info('LocalVideoStagingService: deleted staged file (absolute fallback)', [
                'path' => $absoluteOrRelativePath,
                'deleted' => $deleted,
            ]);

            return $deleted;
        }

        return true;
    }

    public function toRelativePath(string $path): ?string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', rtrim(Storage::disk('local')->path(''), '/'));

        if (str_starts_with($normalizedPath, $root.'/')) {
            return ltrim(substr($normalizedPath, strlen($root) + 1), '/');
        }

        if (str_starts_with($normalizedPath, self::STAGING_DIR.'/')) {
            return $normalizedPath;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function listStagingFiles(): array
    {
        return Storage::disk('local')->files(self::STAGING_DIR);
    }

    public function pruneOrphans(int $olderThanHours = 6): int
    {
        $deleted = 0;
        $cutoff = now()->subHours($olderThanHours)->getTimestamp();
        $referenced = $this->referencedRelativePaths();

        foreach ($this->listStagingFiles() as $relative) {
            if (in_array($relative, $referenced, true)) {
                continue;
            }

            $absolute = Storage::disk('local')->path($relative);

            if (! is_file($absolute) || filemtime($absolute) > $cutoff) {
                continue;
            }

            if ($this->delete($relative)) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            Log::info('LocalVideoStagingService: pruned orphan staging files', [
                'deleted' => $deleted,
                'older_than_hours' => $olderThanHours,
            ]);
        }

        return $deleted;
    }

    /**
     * @return array<int, string>
     */
    protected function referencedRelativePaths(): array
    {
        return Video::query()
            ->whereNotNull('metadata')
            ->get(['metadata'])
            ->map(fn (Video $video): string => (string) data_get($video->metadata, 'local_staging.relative_path', ''))
            ->filter(fn (string $path): bool => $path !== '')
            ->values()
            ->all();
    }
}
