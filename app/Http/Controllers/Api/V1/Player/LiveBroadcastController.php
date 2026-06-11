<?php

namespace App\Http\Controllers\Api\V1\Player;

use App\Http\Controllers\Controller;
use App\Models\LiveShow;
use App\Services\LiveBroadcast\LiveBroadcastSessionService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LiveBroadcastController extends Controller
{
    public function stream(LiveShow $liveShow, string $file, LiveBroadcastSessionService $sessions): BinaryFileResponse
    {
        abort_if($liveShow->status === 'cancelled', 404);
        abort_if(preg_match('/^index(?:\d+\.ts|\.m3u8)$/', $file) !== 1, 404);

        $path = $sessions->hlsSegmentPath($liveShow->id, $file);
        abort_if($path === null || ! is_file($path), 404);

        $mime = str_ends_with($file, '.m3u8')
            ? 'application/vnd.apple.mpegurl'
            : 'video/mp2t';

        return response()->file($path, [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
