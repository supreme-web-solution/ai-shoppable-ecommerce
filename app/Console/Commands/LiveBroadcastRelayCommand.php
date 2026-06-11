<?php

namespace App\Console\Commands;

use App\Services\LiveBroadcast\LiveBroadcastIngestService;
use App\Services\LiveBroadcast\LiveBroadcastSessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class LiveBroadcastRelayCommand extends Command
{
    protected $signature = 'live:broadcast-relay {sessionId}';

    protected $description = 'Relay chunked browser WebM uploads to HLS for in-app viewers';

    public function handle(
        LiveBroadcastSessionService $sessions,
        LiveBroadcastIngestService $ingest,
    ): int {
        $sessionId = (string) $this->argument('sessionId');
        $session = Cache::get($sessions->cacheKey($sessionId));

        if (! is_array($session)) {
            $this->error('Broadcast session not found.');

            return self::FAILURE;
        }

        $dir = (string) ($session['dir'] ?? '');
        $hlsDir = (string) ($session['hls_dir'] ?? $dir.'/hls');

        if ($dir === '' || ! is_dir($dir)) {
            $this->error('Broadcast session is incomplete.');

            return self::FAILURE;
        }

        if (! is_dir($hlsDir) && ! mkdir($hlsDir, 0755, true) && ! is_dir($hlsDir)) {
            $this->error('Could not create HLS output directory.');

            return self::FAILURE;
        }

        $firstChunk = $this->waitForFirstChunk($dir);

        if ($firstChunk === null) {
            $this->error('Timed out waiting for the first broadcast chunk.');

            return self::FAILURE;
        }

        $logPath = storage_path('logs/broadcast-relay-'.$sessionId.'.log');
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $nullDevice, 'a'],
            2 => ['file', $logPath, 'a'],
        ];

        $command = [
            $ingest->ffmpegPath(),
            '-hide_banner',
            '-loglevel', 'info',
            '-analyzeduration', '5M',
            '-probesize', '5M',
            '-fflags', '+genpts+discardcorrupt',
            '-err_detect', 'ignore_err',
            '-f', $firstChunk['format'],
            '-i', 'pipe:0',
            '-c:v', 'libx264',
            '-preset', 'ultrafast',
            '-tune', 'zerolatency',
            '-pix_fmt', 'yuv420p',
            '-g', '30',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-ar', '44100',
            '-f', 'hls',
            '-hls_time', '1',
            '-hls_list_size', '8',
            '-hls_flags', 'delete_segments+append_list+omit_endlist+program_date_time',
            $hlsDir.'/index.m3u8',
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (! is_resource($process)) {
            $this->error('Could not start FFmpeg relay.');

            return self::FAILURE;
        }

        stream_set_blocking($pipes[0], true);
        $stdin = $pipes[0];
        $expectedChunk = 0;
        $idleCycles = 0;

        while (true) {
            $chunkPath = $this->chunkPath($dir, $expectedChunk);

            if (is_file($chunkPath)) {
                $chunk = file_get_contents($chunkPath);

                if ($chunk !== false && $chunk !== '') {
                    $offset = 0;
                    $length = strlen($chunk);

                    while ($offset < $length) {
                        $written = fwrite($stdin, substr($chunk, $offset));

                        if ($written === false) {
                            break 2;
                        }

                        $offset += $written;
                    }
                }

                @unlink($chunkPath);
                $expectedChunk++;
                $idleCycles = 0;

                continue;
            }

            if (is_file($dir.'/.stop')) {
                $idleCycles++;

                if ($idleCycles > 160) {
                    break;
                }
            } else {
                $idleCycles = 0;
            }

            $status = proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            usleep(25000);
        }

        fclose($stdin);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            Log::warning('Broadcast relay ffmpeg exited with error', [
                'session_id' => $sessionId,
                'exit_code' => $exitCode,
                'log' => is_file($logPath) ? File::get($logPath) : '',
            ]);
        }

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{path: string, format: string}|null
     */
    protected function waitForFirstChunk(string $dir): ?array
    {
        for ($attempt = 0; $attempt < 800; $attempt++) {
            foreach (['webm', 'mp4'] as $extension) {
                $path = $this->chunkPath($dir, 0, $extension);

                if (is_file($path)) {
                    return [
                        'path' => $path,
                        'format' => $extension,
                    ];
                }
            }

            if (is_file($dir.'/.stop')) {
                return null;
            }

            usleep(25000);
        }

        return null;
    }

    protected function chunkPath(string $dir, int $index, ?string $extension = null): string
    {
        if ($extension !== null) {
            return $dir.'/'.sprintf('%08d.%s', $index, $extension);
        }

        foreach (['webm', 'mp4'] as $candidate) {
            $path = $this->chunkPath($dir, $index, $candidate);

            if (is_file($path)) {
                return $path;
            }
        }

        return $dir.'/'.sprintf('%08d.webm', $index);
    }
}
