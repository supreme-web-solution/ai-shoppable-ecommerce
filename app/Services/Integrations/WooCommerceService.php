<?php

namespace App\Services\Integrations;

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WooCommerceService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchProducts(Team $team): array
    {
        $settings = (array) data_get($team->settings, 'integrations.woocommerce', []);
        $siteUrl = rtrim(trim((string) ($settings['site_url'] ?? '')), '/');
        $consumerKey = trim((string) ($settings['consumer_key'] ?? ''));
        $consumerSecret = trim((string) ($settings['consumer_secret'] ?? ''));

        if ($siteUrl !== '' && $consumerKey !== '' && $consumerSecret !== '') {
            try {
                $response = Http::timeout(20)
                    ->withBasicAuth($consumerKey, $consumerSecret)
                    ->get("{$siteUrl}/wp-json/wc/v3/products", ['per_page' => 50]);

                if ($response->successful()) {
                    return collect($response->json())
                        ->map(function (array $product) use ($team): array {
                            return [
                                'external_id' => (string) ($product['id'] ?? ''),
                                'title' => (string) ($product['name'] ?? 'WooCommerce Product'),
                                'slug' => (string) ($product['slug'] ?? Str::slug((string) ($product['name'] ?? 'woo-product'))),
                                'description' => (string) ($product['description'] ?? ''),
                                'image_url' => (string) data_get($product, 'images.0.src', ''),
                                'price' => (float) ($product['price'] ?? 0),
                                'currency' => 'USD',
                                'inventory' => (int) ($product['stock_quantity'] ?? 0),
                                'variants' => [],
                            ];
                        })
                        ->filter(fn (array $product): bool => $product['external_id'] !== '')
                        ->values()
                        ->all();
                }

                Log::warning('WooCommerce sync failed', [
                    'team_id' => $team->id,
                    'status' => $response->status(),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('WooCommerce sync exception', [
                    'team_id' => $team->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            [
                'external_id' => 'woo_demo_'.$team->id,
                'title' => 'WooCommerce Demo Product',
                'slug' => 'woocommerce-demo-product',
                'price' => 39.99,
                'currency' => 'USD',
                'inventory' => 15,
                'variants' => [],
            ],
        ];
    }
}
