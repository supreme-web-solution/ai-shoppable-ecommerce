<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Jobs\HandleCloudinaryWebhookJob;
use App\Jobs\SyncExternalCatalogJob;
use App\Models\Team;
use App\Models\WebhookReceipt;
use App\Services\CloudinaryService;
use App\Support\CatalogSyncStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyController extends Controller
{
    public function sync(Request $request)
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
        ]);

        $this->assertTeamAccess($request, $validated['team_id']);

        $team = Team::query()->findOrFail($validated['team_id']);
        $shopify = (array) data_get($team->settings, 'integrations.shopify', []);
        $shopUrl = trim((string) ($shopify['shop_url'] ?? ''));
        $clientId = trim((string) ($shopify['client_id'] ?? ''));
        $clientSecret = trim((string) ($shopify['client_secret'] ?? ''));
        $legacyToken = trim((string) ($shopify['access_token'] ?? ''));

        $hasClientCredentials = $clientId !== '' && $clientSecret !== '';
        $hasLegacyToken = $legacyToken !== '';

        if ($shopUrl === '' || (! $hasClientCredentials && ! $hasLegacyToken)) {
            Log::warning('Shopify catalog sync: queue rejected — credentials missing', [
                'team_id' => $team->id,
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Save your Shop URL, Client ID, and Client Secret before syncing.',
            ], 422);
        }

        $queue = (string) config('queue.names.integration', 'integration');

        Log::info('Shopify catalog sync: queued', [
            'team_id' => $team->id,
            'shop_url' => $shopUrl,
            'queue' => $queue,
        ]);

        CatalogSyncStatus::markQueued($validated['team_id'], 'shopify');

        SyncExternalCatalogJob::dispatch($validated['team_id'], 'shopify')
            ->onQueue($queue);

        return response()->json([
            'ok' => true,
            'provider' => 'shopify',
            'queued' => true,
            'message' => 'Sync started…',
        ]);
    }

    public function syncStatus(Request $request)
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
        ]);

        $this->assertTeamAccess($request, $validated['team_id']);

        $status = CatalogSyncStatus::get($validated['team_id'], 'shopify');

        return response()->json([
            'ok' => true,
            'status' => $status ?? ['state' => 'idle', 'message' => 'No sync in progress.'],
        ]);
    }

    public function webhook(Request $request)
    {
        abort_unless($this->isShopifySignatureValid($request), 401, 'Invalid Shopify webhook signature.');

        return response()->json([
            'ok' => true,
            'provider' => 'shopify',
            'received' => $request->all(),
        ]);
    }

    public function cloudinaryWebhook(Request $request, CloudinaryService $cloudinaryService)
    {
        $payload = $request->all();

        $verification = $cloudinaryService->verifyWebhook([
            'body' => $request->getContent(),
            'timestamp' => (string) $request->header('X-Cld-Timestamp', ''),
            'signature' => (string) $request->header('X-Cld-Signature', ''),
            'payload' => $payload,
        ]);
        abort_unless($verification['verified'] ?? false, 401, 'Invalid Cloudinary webhook signature.');

        $webhookPayload = (array) ($verification['payload'] ?? []);
        $eventKey = $this->cloudinaryEventKey($request, $webhookPayload);

        $receipt = WebhookReceipt::query()->firstOrCreate(
            [
                'provider' => 'cloudinary',
                'event_key' => $eventKey,
            ],
            [
                'delivery_id' => (string) $request->header('X-Cld-Request-Id', ''),
                'payload' => $webhookPayload,
                'processed_at' => now(),
            ],
        );

        if (! $receipt->wasRecentlyCreated) {
            return response()->json([
                'ok' => true,
                'provider' => 'cloudinary',
                'duplicate' => true,
            ]);
        }

        HandleCloudinaryWebhookJob::dispatch($webhookPayload)
            ->onQueue(config('queue.names.webhooks', 'webhooks'));

        return response()->json([
            'ok' => true,
            'provider' => 'cloudinary',
            'queued' => true,
            'event_key' => $eventKey,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function cloudinaryEventKey(Request $request, array $payload): string
    {
        $requestId = trim((string) $request->header('X-Cld-Request-Id', ''));
        if ($requestId !== '') {
            return "request:{$requestId}";
        }

        $notificationId = trim((string) ($payload['notification_id'] ?? ''));
        if ($notificationId !== '') {
            return "notification:{$notificationId}";
        }

        return sha1(json_encode([
            'public_id' => (string) ($payload['public_id'] ?? ''),
            'version' => (string) ($payload['version'] ?? ''),
            'notification_type' => (string) ($payload['notification_type'] ?? ''),
            'asset_id' => (string) ($payload['asset_id'] ?? ''),
            'timestamp' => (string) $request->header('X-Cld-Timestamp', ''),
        ]));
    }

    protected function assertTeamAccess(Request $request, int $teamId): void
    {
        $user = $request->user();
        abort_if(! $user, 401);
        abort_unless($user->team_id === $teamId || $user->teams()->whereKey($teamId)->exists(), 403);
    }

    protected function isShopifySignatureValid(Request $request): bool
    {
        $secret = (string) config('services.shopify.webhook_secret');
        if ($secret === '') {
            Log::warning('Shopify webhook secret is not configured.');

            return false;
        }

        $signature = (string) $request->header('X-Shopify-Hmac-Sha256', '');
        if ($signature === '') {
            return false;
        }

        $computed = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        return hash_equals($computed, $signature);
    }

}
