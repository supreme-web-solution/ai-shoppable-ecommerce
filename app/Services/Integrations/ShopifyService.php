<?php

namespace App\Services\Integrations;

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopifyService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchProducts(Team $team): array
    {
        $settings = (array) data_get($team->settings, 'integrations.shopify', []);
        $shopUrl = trim((string) ($settings['shop_url'] ?? ''));
        $accessToken = trim((string) ($settings['access_token'] ?? ''));

        if ($shopUrl !== '' && $accessToken !== '') {
            try {
                $host = str_contains($shopUrl, '.') ? $shopUrl : "{$shopUrl}.myshopify.com";
                $response = Http::timeout(20)
                    ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
                    ->get("https://{$host}/admin/api/2024-01/products.json", ['limit' => 50]);

                if ($response->successful()) {
                    return collect($response->json('products', []))
                        ->map(function (array $product) use ($team): array {
                            $variant = (array) ($product['variants'][0] ?? []);

                            return [
                                'external_id' => (string) ($product['id'] ?? ''),
                                'title' => (string) ($product['title'] ?? 'Shopify Product'),
                                'slug' => Str::slug((string) ($product['handle'] ?? $product['title'] ?? 'shopify-product')),
                                'description' => (string) ($product['body_html'] ?? ''),
                                'image_url' => (string) data_get($product, 'image.src', ''),
                                'price' => (float) ($variant['price'] ?? 0),
                                'currency' => 'USD',
                                'inventory' => (int) ($variant['inventory_quantity'] ?? 0),
                                'variants' => collect($product['variants'] ?? [])
                                    ->map(fn (array $item): array => [
                                        'external_id' => (string) ($item['id'] ?? ''),
                                        'title' => (string) ($item['title'] ?? 'Default'),
                                        'sku' => (string) ($item['sku'] ?? ''),
                                        'price' => (float) ($item['price'] ?? 0),
                                        'inventory' => (int) ($item['inventory_quantity'] ?? 0),
                                        'options' => array_filter([
                                            'option1' => $item['option1'] ?? null,
                                            'option2' => $item['option2'] ?? null,
                                            'option3' => $item['option3'] ?? null,
                                        ]),
                                    ])
                                    ->all(),
                            ];
                        })
                        ->filter(fn (array $product): bool => $product['external_id'] !== '')
                        ->values()
                        ->all();
                }

                Log::warning('Shopify sync failed', [
                    'team_id' => $team->id,
                    'status' => $response->status(),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Shopify sync exception', [
                    'team_id' => $team->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            [
                'external_id' => 'shopify_demo_'.$team->id,
                'title' => 'Shopify Demo Product',
                'slug' => 'shopify-demo-product',
                'price' => 49.99,
                'currency' => 'USD',
                'inventory' => 25,
                'variants' => [],
            ],
        ];
    }
}
