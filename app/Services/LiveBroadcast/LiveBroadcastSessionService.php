<?php

namespace App\Services\LiveBroadcast;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class LiveBroadcastSessionService
{
    public function cacheKey(string $sessionId): string
    {
        return "live-broadcast:session:{$sessionId}";
    }

    public function showCacheKey(int $liveShowId): string
    {
        return "live-broadcast:show:{$liveShowId}";
    }

    /**
     * @return array{session_id: string, live_show_id: int, dir: string, rtmp_url: string, stopped: bool}
     */
    public function start(int $liveShowId, string $rtmpUrl, LiveBroadcastIngestService $ingest): array
    {
        $sessionId = (string) Str::uuid();
        $dir = storage_path('app/live-broadcast/'.$sessionId);

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Could not create broadcast session directory.');
        }

        $hlsDir = $dir.'/hls';

        if (! is_dir($hlsDir) && ! mkdir($hlsDir, 0755, true) && ! is_dir($hlsDir)) {
            throw new \RuntimeException('Could not create HLS output directory.');
        }

        $session = [
            'session_id' => $sessionId,
            'live_show_id' => $liveShowId,
            'dir' => $dir,
            'hls_dir' => $hlsDir,
            'rtmp_url' => $rtmpUrl,
            'stopped' => false,
            'chunks_received' => 0,
            'last_chunk_at' => null,
        ];

        Cache::put($this->cacheKey($sessionId), $session, now()->addHours(2));
        Cache::put($this->showCacheKey($liveShowId), [
            'session_id' => $sessionId,
            'chunks_received' => 0,
            'last_chunk_at' => null,
        ], now()->addHours(2));

        return $session;
    }

    /**
     * @return array{session_id: string, live_show_id: int, dir: string, rtmp_url: string, stopped: bool}
     */
    public function resolve(string $sessionId, int $liveShowId): array
    {
        $session = Cache::get($this->cacheKey($sessionId));

        if (! is_array($session) || (int) ($session['live_show_id'] ?? 0) !== $liveShowId) {
            throw new \RuntimeException('Broadcast session is invalid or expired.');
        }

        return $session;
    }

    public function appendChunk(
        string $sessionId,
        int $liveShowId,
        int $chunkIndex,
        string $binary,
        string $extension = 'webm',
    ): void {
        $session = $this->resolve($sessionId, $liveShowId);

        if ($session['stopped']) {
            throw new \RuntimeException('Broadcast session has already ended.');
        }

        $extension = in_array($extension, ['webm', 'mp4'], true) ? $extension : 'webm';
        $path = $session['dir'].'/'.sprintf('%08d.%s', $chunkIndex, $extension);

        if (file_put_contents($path, $binary) === false) {
            throw new \RuntimeException('Could not store broadcast chunk.');
        }

        if ($chunkIndex === 0) {
            $this->ensureRelayProcess($sessionId, (string) $session['dir']);
        }

        $timestamp = now()->timestamp;
        $session['chunks_received'] = (int) ($session['chunks_received'] ?? 0) + 1;
        $session['last_chunk_at'] = $timestamp;

        Cache::put($this->cacheKey($sessionId), $session, now()->addHours(2));
        Cache::put($this->showCacheKey($liveShowId), [
            'session_id' => $sessionId,
            'chunks_received' => $session['chunks_received'],
            'last_chunk_at' => $timestamp,
        ], now()->addHours(2));
    }

    public function stop(string $sessionId, int $liveShowId): void
    {
        $session = $this->resolve($sessionId, $liveShowId);
        $session['stopped'] = true;

        Cache::put($this->cacheKey($sessionId), $session, now()->addMinutes(10));
        Cache::forget($this->showCacheKey($liveShowId));

        file_put_contents($session['dir'].'/.stop', '');
    }

    public function playbackUrlForLiveShow(int $liveShowId): string
    {
        return '/api/v1/player/webinars/'.$liveShowId.'/live-stream/index.m3u8';
    }

    /**
     * @return array{session_id: string, dir: string, hls_dir: string}|null
     */
    public function activeSessionForLiveShow(int $liveShowId): ?array
    {
        $show = Cache::get($this->showCacheKey($liveShowId));

        if (! is_array($show)) {
            return null;
        }

        $sessionId = trim((string) ($show['session_id'] ?? ''));

        if ($sessionId === '') {
            return null;
        }

        $session = Cache::get($this->cacheKey($sessionId));

        if (! is_array($session) || (int) ($session['live_show_id'] ?? 0) !== $liveShowId) {
            return null;
        }

        if ((bool) ($session['stopped'] ?? false)) {
            return null;
        }

        return [
            'session_id' => $sessionId,
            'dir' => (string) ($session['dir'] ?? ''),
            'hls_dir' => (string) ($session['hls_dir'] ?? ''),
        ];
    }

    public function hlsSegmentPath(int $liveShowId, string $file): ?string
    {
        $hlsDir = $this->activeHlsDirectory($liveShowId);

        if ($hlsDir === null) {
            return null;
        }

        $path = $hlsDir.'/'.$file;

        if (! is_file($path)) {
            return null;
        }

        $realHlsDir = realpath($hlsDir);
        $realPath = realpath($path);

        if ($realHlsDir === false || $realPath === false || ! str_starts_with($realPath, $realHlsDir)) {
            return null;
        }

        return $realPath;
    }

    protected function activeHlsDirectory(int $liveShowId): ?string
    {
        $show = Cache::get($this->showCacheKey($liveShowId));

        if (! is_array($show)) {
            return null;
        }

        $lastChunk = (int) ($show['last_chunk_at'] ?? 0);

        if ($lastChunk === 0 || (now()->timestamp - $lastChunk) > 60) {
            return null;
        }

        $sessionId = trim((string) ($show['session_id'] ?? ''));

        if ($sessionId === '') {
            return null;
        }

        $session = Cache::get($this->cacheKey($sessionId));
        $hlsDir = is_array($session)
            ? (string) ($session['hls_dir'] ?? '')
            : '';

        if ($hlsDir === '' || ! is_dir($hlsDir)) {
            $hlsDir = storage_path('app/live-broadcast/'.$sessionId.'/hls');
        }

        return is_dir($hlsDir) ? $hlsDir : null;
    }

    /**
     * @return array{active: bool, source_segments: int, last_seen: string|null}
     */
    public function statusForLiveShow(int $liveShowId): array
    {
        $show = Cache::get($this->showCacheKey($liveShowId));

        if (! is_array($show)) {
            return [
                'active' => false,
                'source_segments' => 0,
                'last_seen' => null,
            ];
        }

        $chunks = (int) ($show['chunks_received'] ?? 0);
        $lastChunk = (int) ($show['last_chunk_at'] ?? 0);
        $recent = $lastChunk > 0 && (now()->timestamp - $lastChunk) <= 20;

        return [
            'active' => $recent && $chunks >= 2,
            'source_segments' => $recent ? max(1, $chunks) : 0,
            'last_seen' => $lastChunk > 0 ? date('c', $lastChunk) : null,
        ];
    }

    protected function ensureRelayProcess(string $sessionId, string $dir): void
    {
        $marker = $dir.'/.relay-started';

        if (is_file($marker)) {
            return;
        }

        file_put_contents($marker, (string) now()->timestamp);
        $this->startRelayProcess($sessionId);
    }

    protected function startRelayProcess(string $sessionId): void
    {
        $php = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $id = escapeshellarg($sessionId);

        if (PHP_OS_FAMILY === 'Windows') {
            $command = "start /B \"\" $php $artisan live:broadcast-relay $id";
            pclose(popen($command, 'r'));

            Log::info('Started Windows broadcast relay', ['session_id' => $sessionId]);

            return;
        }

        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'live:broadcast-relay',
            $sessionId,
        ], base_path());
        $process->setTimeout(null);
        $process->disableOutput();
        $process->start();

        Log::info('Started broadcast relay', [
            'session_id' => $sessionId,
            'pid' => $process->getPid(),
        ]);
    }
}
