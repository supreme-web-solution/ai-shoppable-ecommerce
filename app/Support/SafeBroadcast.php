<?php

namespace App\Support;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Log;

class SafeBroadcast
{
    /**
     * Run a broadcast without failing the HTTP request when Reverb/Pusher is offline.
     */
    public static function try(callable $broadcast): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        try {
            $broadcast();
        } catch (BroadcastException $e) {
            Log::warning('Broadcast failed — is Reverb running? (php artisan reverb:start)', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
