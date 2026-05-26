<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Jobs\SyncExternalCatalogJob;
use App\Http\Controllers\Controller;
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

        SyncExternalCatalogJob::dispatch($validated['team_id'], 'woocommerce')
            ->onQueue(config('queue.names.integration', 'integration'));

        return response()->json(['ok' => true, 'provider' => 'woocommerce', 'queued' => true]);
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
