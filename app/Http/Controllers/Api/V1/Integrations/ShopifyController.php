<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Jobs\HandleCloudinaryWebhookJob;
use App\Jobs\SyncExternalCatalogJob;
use App\Http\Controllers\Controller;
use App\Models\WebhookReceipt;
use App\Services\CloudinaryService;
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

        SyncExternalCatalogJob::dispatch($validated['team_id'], 'shopify')
            ->onQueue(config('queue.names.integration', 'integration'));

        return response()->json(['ok' => true, 'provider' => 'shopify', 'queued' => true]);
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
            ->onQueue(config('queue.names.media', 'media'));

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
