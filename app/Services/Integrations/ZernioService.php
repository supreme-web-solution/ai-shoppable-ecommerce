<?php

namespace App\Services\Integrations;

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ZernioService
{
    public function enabled(): bool
    {
        return (bool) config('services.zernio.enabled', false)
            && $this->apiKey() !== '';
    }

    public function apiKey(): string
    {
        return trim((string) config('services.zernio.api_key'));
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('services.zernio.base_url', 'https://zernio.com/api/v1'), '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $payload = []): array
    {
        $http = Http::timeout(30)
            ->withToken($this->apiKey())
            ->acceptJson();

        $url = $this->baseUrl().$path;

        $response = match (strtoupper($method)) {
            'GET' => $http->get($url, $payload),
            'POST' => $http->post($url, $payload),
            'PUT' => $http->put($url, $payload),
            'PATCH' => $http->patch($url, $payload),
            'DELETE' => $http->delete($url, $payload),
            default => throw new \InvalidArgumentException("Unsupported HTTP method [{$method}]."),
        };

        if (! $response->successful()) {
            Log::warning('Zernio API request failed', [
                'method' => $method,
                'path' => $path,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 800),
            ]);

            throw new \RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        return $response->json() ?? [];
    }

    protected function errorMessage(mixed $json, int $status): string
    {
        if (is_array($json)) {
            $message = data_get($json, 'error.message')
                ?? data_get($json, 'message')
                ?? data_get($json, 'error');

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return "Zernio API request failed (HTTP {$status}).";
    }

    public function zernioSettings(Team $team): array
    {
        return (array) data_get($team->settings, 'integrations.zernio', []);
    }

    public function ensureTeamProfile(Team $team): string
    {
        $settings = $this->zernioSettings($team);
        $profileId = trim((string) ($settings['profile_id'] ?? ''));

        if ($profileId !== '') {
            return $profileId;
        }

        $response = $this->request('POST', '/profiles', [
            'name' => Str::limit($team->name.' — '.config('app.name'), 120),
            'description' => 'Social publishing profile for team #'.$team->id,
        ]);

        $profileId = (string) (data_get($response, 'profile._id') ?? data_get($response, '_id') ?? '');

        if ($profileId === '') {
            throw new \RuntimeException('Zernio did not return a profile id.');
        }

        $team->update([
            'settings' => array_replace_recursive((array) $team->settings, [
                'integrations' => [
                    'zernio' => [
                        'profile_id' => $profileId,
                        'profile_created_at' => now()->toIso8601String(),
                    ],
                ],
            ]),
        ]);

        return $profileId;
    }

    public function connectUrl(Team $team, string $platform): string
    {
        $profileId = $this->ensureTeamProfile($team);
        $platform = $this->normalizePlatform($platform);

        $response = $this->request('GET', '/connect/'.$platform, [
            'profileId' => $profileId,
        ]);

        $authUrl = (string) (data_get($response, 'authUrl') ?? data_get($response, 'auth_url') ?? '');

        if ($authUrl === '') {
            throw new \RuntimeException('Zernio did not return a connect URL.');
        }

        return $authUrl;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAccounts(Team $team): array
    {
        $this->ensureTeamProfile($team);

        $response = $this->request('GET', '/accounts');
        $accounts = data_get($response, 'accounts', $response);

        if (! is_array($accounts)) {
            return [];
        }

        return array_values(array_filter($accounts, fn ($row) => is_array($row)));
    }

    /**
     * @param  array<int, array{platform: string, accountId: string}>  $platforms
     * @return array<string, mixed>
     */
    public function createPost(
        string $content,
        array $platforms,
        ?string $scheduledFor = null,
        ?string $timezone = null,
        bool $publishNow = false,
        ?array $mediaUrls = null,
    ): array {
        $payload = [
            'content' => $content,
            'platforms' => array_map(fn (array $row) => [
                'platform' => $this->normalizePlatform((string) ($row['platform'] ?? '')),
                'accountId' => (string) ($row['accountId'] ?? $row['account_id'] ?? ''),
            ], $platforms),
        ];

        if ($publishNow) {
            $payload['publishNow'] = true;
        } elseif ($scheduledFor) {
            $payload['scheduledFor'] = $scheduledFor;
            $payload['timezone'] = $timezone ?: config('app.timezone', 'UTC');
        }

        if ($mediaUrls && count($mediaUrls) > 0) {
            $payload['mediaUrls'] = array_values(array_filter($mediaUrls));
        }

        return $this->request('POST', '/posts', $payload);
    }

    /**
     * @return array<int, string>
     */
    public function supportedPlatforms(): array
    {
        return [
            'instagram',
            'facebook',
            'tiktok',
            'youtube',
            'linkedin',
            'threads',
            'twitter',
            'pinterest',
        ];
    }

    protected function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return match ($platform) {
            'x' => 'twitter',
            default => $platform,
        };
    }
}
