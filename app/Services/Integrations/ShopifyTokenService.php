<?php

namespace App\Services\Integrations;

use App\Models\Team;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopifyTokenService
{
    /**
     * @return array{token: string|null, error: string|null}
     */
    public function resolveAccessToken(Team $team): array
    {
        $settings = (array) data_get($team->settings, 'integrations.shopify', []);
        $shopUrl = trim((string) ($settings['shop_url'] ?? ''));
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        $clientSecret = trim((string) ($settings['client_secret'] ?? ''));
        $legacyToken = trim((string) ($settings['access_token'] ?? ''));

        if ($legacyToken !== '' && $clientId === '' && $clientSecret === '') {
            return ['token' => $legacyToken, 'error' => null];
        }

        if ($shopUrl === '' || $clientId === '' || $clientSecret === '') {
            return [
                'token' => null,
                'error' => 'Shop URL, Client ID, and Client Secret are required.',
            ];
        }

        $cacheKey = $this->cacheKey($team->id);
        $cached = Cache::get($cacheKey);

        if (
            is_array($cached)
            && ($cached['client_id'] ?? '') === $clientId
            && is_string($cached['access_token'] ?? null)
            && ($cached['expires_at'] ?? 0) > time() + 60
        ) {
            return ['token' => $cached['access_token'], 'error' => null];
        }

        return $this->exchangeClientCredentials($team->id, $shopUrl, $clientId, $clientSecret);
    }

    public function forget(int $teamId): void
    {
        Cache::forget($this->cacheKey($teamId));
    }

    /**
     * @return array{token: string|null, error: string|null}
     */
    protected function exchangeClientCredentials(int $teamId, string $shopUrl, string $clientId, string $clientSecret): array
    {
        $host = $this->normalizeShopHost($shopUrl);
        $endpoint = "https://{$host}/admin/oauth/access_token";

        Log::info('Shopify OAuth: requesting access token via client credentials', [
            'team_id' => $teamId,
            'host' => $host,
        ]);

        try {
            $response = Http::timeout(30)
                ->asForm()
                ->post($endpoint, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);

            if (! $response->successful()) {
                $error = $this->errorMessageFromResponse($response->status(), $response->body());

                Log::warning('Shopify OAuth: token exchange failed', [
                    'team_id' => $teamId,
                    'host' => $host,
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 800),
                ]);

                return ['token' => null, 'error' => $error];
            }

            $accessToken = trim((string) $response->json('access_token', ''));
            $expiresIn = max(300, (int) $response->json('expires_in', 86399));

            if ($accessToken === '') {
                return [
                    'token' => null,
                    'error' => 'Shopify returned an empty access token.',
                ];
            }

            $expiresAt = time() + $expiresIn;

            Cache::put($this->cacheKey($teamId), [
                'access_token' => $accessToken,
                'client_id' => $clientId,
                'expires_at' => $expiresAt,
            ], now()->addSeconds($expiresIn - 300));

            Log::info('Shopify OAuth: access token obtained', [
                'team_id' => $teamId,
                'host' => $host,
                'expires_in' => $expiresIn,
                'scope' => $response->json('scope'),
            ]);

            return ['token' => $accessToken, 'error' => null];
        } catch (\Throwable $exception) {
            Log::error('Shopify OAuth: token exchange exception', [
                'team_id' => $teamId,
                'host' => $host,
                'error' => $exception->getMessage(),
            ]);

            return [
                'token' => null,
                'error' => 'Could not reach Shopify to obtain an access token: '.$exception->getMessage(),
            ];
        }
    }

    protected function errorMessageFromResponse(int $status, string $body): string
    {
        if ($status === 401 || $status === 403) {
            return 'Invalid Client ID or Client Secret, or the app is not installed on this store. In the Dev Dashboard, release a version with read_products scopes and install the app on your store.';
        }

        if ($status === 404) {
            return 'Shop URL not found. Use your myshopify.com hostname (e.g. your-store.myshopify.com).';
        }

        $decoded = json_decode($body, true);
        $apiError = is_array($decoded)
            ? (string) (data_get($decoded, 'error_description') ?? data_get($decoded, 'error') ?? data_get($decoded, 'errors'))
            : '';

        if ($apiError !== '') {
            return is_string($apiError) ? $apiError : json_encode($apiError);
        }

        return "Shopify OAuth error (HTTP {$status}).";
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

    protected function cacheKey(int $teamId): string
    {
        return "shopify.oauth.{$teamId}";
    }
}
