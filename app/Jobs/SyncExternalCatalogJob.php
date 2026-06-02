<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Team;
use App\Services\Integrations\ShopifyService;
use App\Services\Integrations\WooCommerceService;
use App\Support\CatalogSyncStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncExternalCatalogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $teamId,
        public string $provider,
    ) {}

    public function handle(ShopifyService $shopifyService, WooCommerceService $wooCommerceService): void
    {
        Log::info('External catalog sync job started', [
            'team_id' => $this->teamId,
            'provider' => $this->provider,
            'queue' => $this->queue ?? config('queue.names.integration', 'integration'),
        ]);

        CatalogSyncStatus::markRunning($this->teamId, $this->provider);

        $team = Team::query()->find($this->teamId);

        if (! $team) {
            CatalogSyncStatus::markFailed($this->teamId, $this->provider, 'Team not found.');

            Log::warning('External catalog sync job aborted: team not found', [
                'team_id' => $this->teamId,
                'provider' => $this->provider,
            ]);

            return;
        }

        if ($this->provider === 'woocommerce') {
            $fetch = $wooCommerceService->fetchProducts($team);
            $products = $fetch['products'];
            $fetchError = $fetch['error'];
        } else {
            $fetch = $shopifyService->fetchProducts($team);
            $products = $fetch['products'];
            $fetchError = $fetch['error'];
        }

        if ($fetchError !== null) {
            CatalogSyncStatus::markFailed($this->teamId, $this->provider, $fetchError);

            Log::warning('External catalog sync job failed', [
                'team_id' => $this->teamId,
                'provider' => $this->provider,
                'error' => $fetchError,
            ]);

            return;
        }

        if ($products === []) {
            CatalogSyncStatus::markFailed(
                $this->teamId,
                $this->provider,
                'No products returned from '.($this->provider === 'shopify' ? 'Shopify' : 'WooCommerce').'.',
            );

            Log::warning('External catalog sync job finished with no products', [
                'team_id' => $this->teamId,
                'provider' => $this->provider,
            ]);

            return;
        }

        $created = 0;
        $updated = 0;
        $variantCount = 0;

        foreach ($products as $productData) {
            $existing = Product::query()
                ->where('team_id', $team->id)
                ->where('external_id', $productData['external_id'] ?? null)
                ->first();

            $product = Product::query()->updateOrCreate(
                [
                    'team_id' => $team->id,
                    'external_id' => $productData['external_id'] ?? null,
                ],
                [
                    'source' => $this->provider,
                    'title' => $productData['title'],
                    'slug' => $productData['slug'] ?? Str::slug($productData['title']),
                    'description' => $productData['description'] ?? null,
                    'image_url' => $productData['image_url'] ?? null,
                    'currency' => $productData['currency'] ?? 'USD',
                    'price' => $productData['price'] ?? 0,
                    'inventory' => $productData['inventory'] ?? 0,
                    'is_active' => true,
                ],
            );

            if ($existing) {
                $updated++;
            } else {
                $created++;
            }

            Log::info('External catalog sync: product upserted', [
                'team_id' => $team->id,
                'provider' => $this->provider,
                'product_id' => $product->id,
                'external_id' => $product->external_id,
                'title' => $product->title,
                'variant_count' => count($productData['variants'] ?? []),
            ]);

            foreach ($productData['variants'] ?? [] as $variantData) {
                ProductVariant::query()->updateOrCreate(
                    [
                        'team_id' => $team->id,
                        'product_id' => $product->id,
                        'external_id' => $variantData['external_id'] ?? null,
                    ],
                    [
                        'title' => $variantData['title'] ?? 'Default',
                        'sku' => $variantData['sku'] ?? null,
                        'options' => $variantData['options'] ?? [],
                        'price' => $variantData['price'] ?? $product->price,
                        'inventory' => $variantData['inventory'] ?? 0,
                        'is_default' => false,
                    ],
                );
                $variantCount++;
            }
        }

        $message = sprintf(
            'Imported %d product%s (%d new, %d updated).',
            count($products),
            count($products) === 1 ? '' : 's',
            $created,
            $updated,
        );

        CatalogSyncStatus::markCompleted($this->teamId, $this->provider, [
            'message' => $message,
            'products_created' => $created,
            'products_updated' => $updated,
            'total_products' => count($products),
        ]);

        Log::info('External catalog sync job completed', [
            'team_id' => $this->teamId,
            'provider' => $this->provider,
            'products_created' => $created,
            'products_updated' => $updated,
            'variants_upserted' => $variantCount,
            'total_products' => count($products),
        ]);
    }
}
