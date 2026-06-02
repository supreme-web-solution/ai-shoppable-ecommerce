<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Jobs\SyncExternalCatalogJob;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\CatalogSyncStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WooCommerceController extends Controller
{
    public function sync(Request $request)
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
        ]);

        $this->assertTeamAccess($request, $validated['team_id']);
        $team = Team::query()->findOrFail($validated['team_id']);
        $woo = (array) data_get($team->settings, 'integrations.woocommerce', []);
        $siteUrl = trim((string) ($woo['site_url'] ?? ''));
        $consumerKey = trim((string) ($woo['consumer_key'] ?? ''));
        $consumerSecret = trim((string) ($woo['consumer_secret'] ?? ''));

        if ($siteUrl === '' || $consumerKey === '' || $consumerSecret === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Save your Site URL, Consumer key, and Consumer secret before syncing.',
            ], 422);
        }

        $queue = (string) config('queue.names.integration', 'integration');

        Log::info('WooCommerce catalog sync: queued', [
            'team_id' => $team->id,
            'site_url' => $siteUrl,
            'queue' => $queue,
        ]);

        CatalogSyncStatus::markQueued($validated['team_id'], 'woocommerce');

        SyncExternalCatalogJob::dispatch($validated['team_id'], 'woocommerce')
            ->onQueue($queue);

        return response()->json([
            'ok' => true,
            'provider' => 'woocommerce',
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
        $status = CatalogSyncStatus::get($validated['team_id'], 'woocommerce');

        return response()->json([
            'ok' => true,
            'status' => $status ?? ['state' => 'idle', 'message' => 'No sync in progress.'],
        ]);
    }

    public function webhook(Request $request)
    {
        abort_unless($this->isWebhookSignatureValid($request), 401, 'Invalid WooCommerce webhook signature.');

        return response()->json([
            'ok' => true,
            'provider' => 'woocommerce',
            'received' => $request->all(),
        ]);
    }

    protected function assertTeamAccess(Request $request, int $teamId): void
    {
        $user = $request->user();
        abort_if(! $user, 401);
        abort_unless($user->team_id === $teamId || $user->teams()->whereKey($teamId)->exists(), 403);
    }

    protected function isWebhookSignatureValid(Request $request): bool
    {
        $secret = (string) config('services.woocommerce.webhook_secret');
        if ($secret === '') {
            Log::warning('WooCommerce webhook secret is not configured.');

            return false;
        }

        $signature = (string) $request->header('X-WC-Webhook-Signature', '');
        if ($signature === '') {
            return false;
        }

        $computed = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        return hash_equals($computed, $signature);
    }
}
