<?php

namespace App\Services\Integrations;

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopifyService
{
    public function __construct(
        protected ShopifyTokenService $tokenService,
    ) {}

    /**
     * @return array{products: array<int, array<string, mixed>>, error: string|null}
     */
    public function fetchProducts(Team $team): array
    {
        $settings = (array) data_get($team->settings, 'integrations.shopify', []);
        $shopUrl = trim((string) ($settings['shop_url'] ?? ''));
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        $clientSecret = trim((string) ($settings['client_secret'] ?? ''));
        $legacyToken = trim((string) ($settings['access_token'] ?? ''));

        Log::info('Shopify catalog sync: fetch started', [
            'team_id' => $team->id,
            'shop_url_configured' => $shopUrl !== '',
            'client_credentials_configured' => $clientId !== '' && $clientSecret !== '',
            'legacy_access_token_configured' => $legacyToken !== '',
            'shopify_enabled' => (bool) ($settings['enabled'] ?? false),
        ]);

        $tokenResult = $this->tokenService->resolveAccessToken($team);

        if ($tokenResult['token'] === null) {
            Log::warning('Shopify catalog sync: missing credentials', [
                'team_id' => $team->id,
                'hint' => 'Save Shop URL, Client ID, and Client Secret under Settings → Integrations.',
            ]);

            return [
                'products' => [],
                'error' => $tokenResult['error'] ?? 'Shopify credentials are required.',
            ];
        }

        $accessToken = $tokenResult['token'];

        try {
            $host = $this->normalizeShopHost($shopUrl);
            $endpoint = "https://{$host}/admin/api/2024-01/products.json";

            Log::info('Shopify catalog sync: calling Admin API', [
                'team_id' => $team->id,
                'host' => $host,
                'endpoint' => $endpoint,
                'limit' => 50,
            ]);

            $response = Http::timeout(30)
                ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
                ->get($endpoint, ['limit' => 50]);

            if ($response->status() === 401) {
                $this->tokenService->forget($team->id);
                $tokenResult = $this->tokenService->resolveAccessToken($team->fresh());

                if ($tokenResult['token'] !== null) {
                    $response = Http::timeout(30)
                        ->withHeaders(['X-Shopify-Access-Token' => $tokenResult['token']])
                        ->get($endpoint, ['limit' => 50]);
                }
            }

            if (! $response->successful()) {
                $error = $this->errorMessageFromResponse($response->status(), $response->body());

                Log::warning('Shopify catalog sync: API request failed', [
                    'team_id' => $team->id,
                    'host' => $host,
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 1200),
                ]);

                return [
                    'products' => [],
                    'error' => $error,
                ];
            }

            $rawProducts = $response->json('products', []);

            $mapped = collect($rawProducts)
                ->map(function (array $product): array {
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

            Log::info('Shopify catalog sync: fetch completed', [
                'team_id' => $team->id,
                'host' => $host,
                'raw_count' => is_countable($rawProducts) ? count($rawProducts) : 0,
                'mapped_count' => count($mapped),
            ]);

            return [
                'products' => $mapped,
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            Log::error('Shopify catalog sync: exception', [
                'team_id' => $team->id,
                'error' => $exception->getMessage(),
                'trace' => Str::limit($exception->getTraceAsString(), 2000),
            ]);

            return [
                'products' => [],
                'error' => 'Could not reach Shopify: '.$exception->getMessage(),
            ];
        }
    }

    protected function errorMessageFromResponse(int $status, string $body): string
    {
        if ($status === 401) {
            return 'Invalid Shopify credentials or expired access token. Check Client ID and Client Secret in the Dev Dashboard, ensure the app is installed on your store, and try syncing again.';
        }

        if ($status === 403) {
            return 'Shopify rejected the request (403). Ensure your custom app has read_products (and read_inventory) scopes, then reinstall the app.';
        }

        if ($status === 404) {
            return 'Shop URL not found. Use your myshopify.com hostname (e.g. your-store.myshopify.com).';
        }

        $decoded = json_decode($body, true);
        $apiError = is_array($decoded)
            ? (string) (data_get($decoded, 'errors') ?? data_get($decoded, 'error'))
            : '';

        if ($apiError !== '') {
            return is_string($apiError) ? $apiError : json_encode($apiError);
        }

        return "Shopify API error (HTTP {$status}).";
    }

    protected function normalizeShopHost(string $shopUrl): string
    {
        $shopUrl = strtolower(trim($shopUrl));
        $shopUrl = preg_replace('#^https?://#', '', $shopUrl) ?? $shopUrl;
        $shopUrl = rtrim($shopUrl, '/');

        if (str_contains($shopUrl, '.')) {
            return $shopUrl;
        }

        return "{$shopUrl}.myshopify.com";
    }
}
