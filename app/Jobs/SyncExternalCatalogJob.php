<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Team;
use App\Services\Integrations\ShopifyService;
use App\Services\Integrations\WooCommerceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        $team = Team::query()->find($this->teamId);

        if (! $team) {
            return;
        }

        $products = $this->provider === 'woocommerce'
            ? $wooCommerceService->fetchProducts($team)
            : $shopifyService->fetchProducts($team);

        foreach ($products as $productData) {
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
            }
        }
    }
}
