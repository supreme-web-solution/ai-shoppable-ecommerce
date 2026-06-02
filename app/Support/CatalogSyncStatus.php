<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class CatalogSyncStatus
{
    public static function cacheKey(int $teamId, string $provider): string
    {
        return "catalog_sync:{$teamId}:{$provider}";
    }

    /**
     * @return array{state: string, message?: string, products_created?: int, products_updated?: int, total_products?: int, finished_at?: string}|null
     */
    public static function get(int $teamId, string $provider): ?array
    {
        $payload = Cache::get(self::cacheKey($teamId, $provider));

        return is_array($payload) ? $payload : null;
    }

    public static function markRunning(int $teamId, string $provider): void
    {
        Cache::put(self::cacheKey($teamId, $provider), [
            'state' => 'running',
            'message' => 'Connecting to Shopify and importing products…',
            'started_at' => now()->toIso8601String(),
        ], now()->addMinutes(10));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function markCompleted(int $teamId, string $provider, array $extra = []): void
    {
        Cache::put(self::cacheKey($teamId, $provider), [
            'state' => 'completed',
            'message' => $extra['message'] ?? 'Sync completed successfully.',
            'products_created' => (int) ($extra['products_created'] ?? 0),
            'products_updated' => (int) ($extra['products_updated'] ?? 0),
            'total_products' => (int) ($extra['total_products'] ?? 0),
            'finished_at' => now()->toIso8601String(),
        ], now()->addHours(1));
    }

    public static function markFailed(int $teamId, string $provider, string $message): void
    {
        Cache::put(self::cacheKey($teamId, $provider), [
            'state' => 'failed',
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
        ], now()->addHours(1));
    }

    public static function markQueued(int $teamId, string $provider): void
    {
        Cache::put(self::cacheKey($teamId, $provider), [
            'state' => 'queued',
            'message' => 'Sync queued — waiting for worker…',
            'queued_at' => now()->toIso8601String(),
        ], now()->addMinutes(10));
    }
}
