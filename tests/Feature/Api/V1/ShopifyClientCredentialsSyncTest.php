<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SyncExternalCatalogJob;
use App\Models\Team;
use App\Models\User;
use App\Services\Integrations\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyClientCredentialsSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopify_sync_exchanges_client_credentials_and_imports_products(): void
    {
        Http::fake([
            'demo-store.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'oauth-session-token',
                'scope' => 'read_products',
                'expires_in' => 86399,
            ], 200),
            'demo-store.myshopify.com/admin/api/2024-01/products.json*' => Http::response([
                'products' => [
                    [
                        'id' => 9001,
                        'title' => 'Synced Hoodie',
                        'handle' => 'synced-hoodie',
                        'body_html' => 'Warm and cozy',
                        'image' => ['src' => 'https://cdn.shopify.com/hoodie.jpg'],
                        'variants' => [
                            [
                                'id' => 8001,
                                'title' => 'Default',
                                'sku' => 'HD-1',
                                'price' => '49.00',
                                'inventory_quantity' => 12,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $team = $this->createTeamWithShopifyCredentials();

        $job = new SyncExternalCatalogJob($team->id, 'shopify');
        $job->handle(app(ShopifyService::class), app(\App\Services\Integrations\WooCommerceService::class));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://demo-store.myshopify.com/admin/oauth/access_token'
                && $request['grant_type'] === 'client_credentials'
                && $request['client_id'] === 'test-client-id'
                && $request['client_secret'] === 'test-client-secret';
        });

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://demo-store.myshopify.com/admin/api/2024-01/products.json')
                && $request->hasHeader('X-Shopify-Access-Token', 'oauth-session-token');
        });

        $this->assertDatabaseHas('products', [
            'team_id' => $team->id,
            'external_id' => '9001',
            'title' => 'Synced Hoodie',
            'source' => 'shopify',
        ]);
    }

    public function test_shopify_sync_reuses_cached_oauth_token(): void
    {
        Http::fake([
            'demo-store.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'oauth-session-token',
                'scope' => 'read_products',
                'expires_in' => 86399,
            ], 200),
            'demo-store.myshopify.com/admin/api/2024-01/products.json*' => Http::response([
                'products' => [
                    [
                        'id' => 9002,
                        'title' => 'Cached Token Tee',
                        'handle' => 'cached-token-tee',
                        'variants' => [
                            ['id' => 8002, 'title' => 'Default', 'price' => '19.00', 'inventory_quantity' => 3],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $team = $this->createTeamWithShopifyCredentials();
        $service = app(ShopifyService::class);

        $service->fetchProducts($team);
        $service->fetchProducts($team->fresh());

        Http::assertSentCount(3);
    }

    protected function createTeamWithShopifyCredentials(): Team
    {
        $owner = User::factory()->create();

        $team = Team::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Shopify Team',
            'slug' => 'shopify-team',
            'checkout_mode' => 'hybrid',
            'external_provider' => 'shopify',
            'settings' => [
                'integrations' => [
                    'shopify' => [
                        'enabled' => true,
                        'shop_url' => 'demo-store.myshopify.com',
                        'client_id' => 'test-client-id',
                        'client_secret' => 'test-client-secret',
                    ],
                ],
            ],
        ]);

        $team->users()->attach($owner->id, ['role' => 'owner']);
        $owner->update(['team_id' => $team->id]);

        return $team;
    }
}
