<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;

class VideoChunkUploadService
{
    public const CHUNK_DIR = 'uploads/videos/chunks';

    public function storeChunk(string $token, int $chunkIndex, string $contents): void
    {
        Storage::disk('local')->put($this->chunkPath($token, $chunkIndex), $contents);
    }

    public function mergeChunks(string $token, int $totalChunks, string $finalRelativePath): string
    {
        $disk = Storage::disk('local');
        $absolute = $disk->path($finalRelativePath);
        $directory = dirname($absolute);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $output = fopen($absolute, 'wb');

        if ($output === false) {
            throw new \RuntimeException("Unable to create merged video file at {$absolute}");
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $chunkPath = $this->chunkPath($token, $index);

                if (! $disk->exists($chunkPath)) {
                    throw new \RuntimeException("Missing upload chunk {$index} for token {$token}");
                }

                $chunk = $disk->get($chunkPath);
                fwrite($output, $chunk);
            }
        } finally {
            fclose($output);
            $this->deleteChunks($token);
        }

        return $absolute;
    }

    public function deleteChunks(string $token): void
    {
        Storage::disk('local')->deleteDirectory(self::CHUNK_DIR.'/'.$token);
    }

    protected function chunkPath(string $token, int $chunkIndex): string
    {
        return self::CHUNK_DIR.'/'.$token.'/'.$chunkIndex.'.part';
    }
}
