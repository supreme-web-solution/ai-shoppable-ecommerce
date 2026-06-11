<?php

namespace App\Services\LiveBroadcast;

use Symfony\Component\Process\Process;

class LiveBroadcastIngestService
{
    public function ffmpegPath(): string
    {
        return trim((string) config('services.live_broadcast.ffmpeg_path', 'ffmpeg'));
    }

    public function isAvailable(): bool
    {
        $process = new Process([$this->ffmpegPath(), '-version']);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Relay a WebM stream from the given input resource to an RTMP destination.
     *
     * @param  resource  $inputStream
     */
    public function relayWebmToRtmp(string $rtmpUrl, $inputStream): int
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = [
            $this->ffmpegPath(),
            '-hide_banner',
            '-loglevel', 'warning',
            '-fflags', '+genpts',
            '-f', 'webm',
            '-i', 'pipe:0',
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-tune', 'zerolatency',
            '-maxrate', '2500k',
            '-bufsize', '5000k',
            '-pix_fmt', 'yuv420p',
            '-g', '60',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-ar', '44100',
            '-f', 'flv',
            $rtmpUrl,
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (! is_resource($process)) {
            throw new \RuntimeException('Could not start broadcast encoder.');
        }

        stream_set_blocking($pipes[0], true);
        stream_set_blocking($inputStream, true);

        $stdin = $pipes[0];

        while (! feof($inputStream)) {
            $chunk = fread($inputStream, 65536);

            if ($chunk === false) {
                break;
            }

            if ($chunk === '') {
                $status = proc_get_status($process);

                if (! $status['running']) {
                    break;
                }

                usleep(10000);

                continue;
            }

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

        fclose($stdin);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 && is_string($stderr) && trim($stderr) !== '') {
            throw new \RuntimeException(trim($stderr));
        }

        return $exitCode;
    }
}
