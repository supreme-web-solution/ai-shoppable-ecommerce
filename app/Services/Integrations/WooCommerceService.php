<?php

namespace App\Services\Integrations;

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WooCommerceService
{
    /**
     * @return array{products: array<int, array<string, mixed>>, error: string|null}
     */
    public function fetchProducts(Team $team): array
    {
        $settings = (array) data_get($team->settings, 'integrations.woocommerce', []);
        $siteUrl = rtrim(trim((string) ($settings['site_url'] ?? '')), '/');
        $consumerKey = trim((string) ($settings['consumer_key'] ?? ''));
        $consumerSecret = trim((string) ($settings['consumer_secret'] ?? ''));

        Log::info('WooCommerce catalog sync: fetch started', [
            'team_id' => $team->id,
            'site_url_configured' => $siteUrl !== '',
            'consumer_key_configured' => $consumerKey !== '',
            'consumer_secret_configured' => $consumerSecret !== '',
            'woocommerce_enabled' => (bool) ($settings['enabled'] ?? false),
        ]);

        if ($siteUrl === '' || $consumerKey === '' || $consumerSecret === '') {
            return [
                'products' => [],
                'error' => 'Site URL, Consumer key, and Consumer secret are required.',
            ];
        }

        $endpoint = "{$siteUrl}/wp-json/wc/v3/products";

        try {
            $response = Http::timeout(30)
                ->withBasicAuth($consumerKey, $consumerSecret)
                ->get($endpoint, ['per_page' => 50]);

            if (! $response->successful()) {
                // Some hosts strip Authorization headers. Retry with query params.
                $response = Http::timeout(30)->get($endpoint, [
                    'per_page' => 50,
                    'consumer_key' => $consumerKey,
                    'consumer_secret' => $consumerSecret,
                ]);
            }

            if (! $response->successful()) {
                $error = $this->errorMessageFromResponse($response->status(), $response->body());

                Log::warning('WooCommerce catalog sync: API request failed', [
                    'team_id' => $team->id,
                    'site_url' => $siteUrl,
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 1200),
                ]);

                return ['products' => [], 'error' => $error];
            }

            $rawProducts = is_array($response->json()) ? $response->json() : [];

            $mapped = collect($rawProducts)
                ->map(function (array $product): array {
                    $isVariable = (string) ($product['type'] ?? '') === 'variable';
                    $baseExternalId = (string) ($product['id'] ?? '');
                    $basePrice = (float) ($product['price'] ?? $product['regular_price'] ?? 0);
                    $variantExternalId = $isVariable ? '' : $baseExternalId;

                    return [
                        'external_id' => $baseExternalId,
                        'title' => (string) ($product['name'] ?? 'WooCommerce Product'),
                        'slug' => (string) ($product['slug'] ?? Str::slug((string) ($product['name'] ?? 'woo-product'))),
                        'description' => (string) ($product['description'] ?? ''),
                        'image_url' => (string) data_get($product, 'images.0.src', ''),
                        'price' => $basePrice,
                        'currency' => (string) ($product['currency'] ?? 'USD'),
                        'inventory' => (int) ($product['stock_quantity'] ?? 0),
                        'variants' => [
                            [
                                'external_id' => $variantExternalId,
                                'title' => 'Default',
                                'sku' => (string) ($product['sku'] ?? ''),
                                'price' => $basePrice,
                                'inventory' => (int) ($product['stock_quantity'] ?? 0),
                                'options' => [],
                            ],
                        ],
                    ];
                })
                ->filter(fn (array $product): bool => $product['external_id'] !== '')
                ->values()
                ->all();

            Log::info('WooCommerce catalog sync: fetch completed', [
                'team_id' => $team->id,
                'site_url' => $siteUrl,
                'raw_count' => count($rawProducts),
                'mapped_count' => count($mapped),
            ]);

            return ['products' => $mapped, 'error' => null];
        } catch (\Throwable $exception) {
            Log::error('WooCommerce catalog sync: exception', [
                'team_id' => $team->id,
                'site_url' => $siteUrl,
                'error' => $exception->getMessage(),
            ]);

            return [
                'products' => [],
                'error' => 'Could not reach WooCommerce: '.$exception->getMessage(),
            ];
        }
    }

    protected function errorMessageFromResponse(int $status, string $body): string
    {
        if ($status === 401 || $status === 403) {
            return 'Invalid WooCommerce API credentials. Generate a new REST API key with Read/Write permissions under WooCommerce → Settings → Advanced → REST API.';
        }

        if ($status === 404) {
            return 'WooCommerce API not found. Ensure the Site URL is correct and points to a WooCommerce store with permalinks enabled.';
        }

        $decoded = json_decode($body, true);
        $apiError = is_array($decoded)
            ? (string) (data_get($decoded, 'message') ?? data_get($decoded, 'code'))
            : '';

        if ($apiError !== '') {
            return $apiError;
        }

        return "WooCommerce API error (HTTP {$status}).";
    }
}
