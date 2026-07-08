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

    protected function profiles(): ZernioProfileManager
    {
        return app(ZernioProfileManager::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $payload = []): array
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

            throw new ZernioApiException(
                $this->errorMessage($response->json(), $response->status()),
                $response->status(),
            );
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

    /**
     * @return array<string, mixed>
     */
    public function createProfile(string $name, string $description): array
    {
        return $this->request('POST', '/profiles', [
            'name' => $name,
            'description' => $description,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProfiles(): array
    {
        $response = $this->request('GET', '/profiles');
        $profiles = data_get($response, 'profiles', $response);

        if (! is_array($profiles)) {
            return [];
        }

        return array_values(array_filter($profiles, fn ($row) => is_array($row)));
    }

    public function ensureTeamProfile(Team $team): string
    {
        return $this->profiles()->ensureForTeam($team);
    }

    public function connectUrl(Team $team, string $platform, ?string $redirectUrl = null): string
    {
        $platform = $this->normalizePlatform($platform);

        return $this->profiles()->withProfile($team, function (string $profileId) use ($platform, $redirectUrl, $team): string {
            $query = ['profileId' => $profileId];

            if ($redirectUrl) {
                $query['redirect_url'] = $redirectUrl;
                $query['redirectUrl'] = $redirectUrl;
            }

            Log::info('Zernio API: GET /connect request', [
                'team_id' => $team->id,
                'platform' => $platform,
                'profile_id' => $profileId,
                'redirect_url' => $redirectUrl,
            ]);

            $response = $this->request('GET', '/connect/'.$platform, $query);

            $authUrl = (string) (data_get($response, 'authUrl') ?? data_get($response, 'auth_url') ?? '');

            Log::info('Zernio API: GET /connect response', [
                'team_id' => $team->id,
                'platform' => $platform,
                'auth_url' => $authUrl !== '' ? $authUrl : null,
                'response_keys' => array_keys($response),
            ]);

            if ($authUrl === '') {
                throw new ZernioApiException('Zernio did not return a connect URL.');
            }

            return $authUrl;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAccounts(Team $team): array
    {
        return $this->profiles()->withProfile($team, fn (string $profileId): array => $this->listAccountsForProfile($profileId));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAccountsForProfile(string $profileId): array
    {
        $response = $this->request('GET', '/accounts', [
            'profileId' => $profileId,
        ]);

        $accounts = data_get($response, 'accounts', $response);

        if (! is_array($accounts)) {
            return [];
        }

        return array_values(array_filter($accounts, function ($row) use ($profileId) {
            if (! is_array($row)) {
                return false;
            }

            $rowProfileId = $this->stringifyValue(
                data_get($row, 'profileId')
                ?? data_get($row, 'profile_id')
                ?? data_get($row, 'profile._id')
                ?? data_get($row, 'profile.id')
            );

            if ($rowProfileId === '') {
                return true;
            }

            return $rowProfileId === $profileId;
        }));
    }

    protected function stringifyValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $string = $this->stringifyValue($item);

                if ($string !== '') {
                    return $string;
                }
            }
        }

        return '';
    }

    public function disconnectAccount(Team $team, string $accountId): void
    {
        $accountId = trim($accountId);

        if ($accountId === '') {
            throw new ZernioApiException('Account id is required.');
        }

        $this->profiles()->withProfile($team, function (string $profileId) use ($accountId): void {
            $this->request('DELETE', '/accounts/'.$accountId, [
                'profileId' => $profileId,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function completeOAuthConnection(Team $team, string $platform, string $code, string $state): array
    {
        $platform = $this->normalizePlatform($platform);

        return $this->profiles()->withProfile(
            $team,
            function (string $profileId) use ($team, $platform, $code, $state): array {
                Log::info('Zernio API: POST /connect exchange', [
                    'team_id' => $team->id,
                    'platform' => $platform,
                    'profile_id' => $profileId,
                    'has_code' => $code !== '',
                    'has_state' => $state !== '',
                ]);

                $response = $this->request('POST', '/connect/'.$platform, [
                    'code' => $code,
                    'state' => $state,
                    'profileId' => $profileId,
                ]);

                Log::info('Zernio API: POST /connect exchange response', [
                    'team_id' => $team->id,
                    'platform' => $platform,
                    'account_id' => data_get($response, 'account._id') ?? data_get($response, 'account.id'),
                    'response_keys' => array_keys($response),
                ]);

                return $response;
            },
        );
    }

    /**
     * @param  array<int, array{platform: string, accountId: string, content?: string, platformSpecificData?: array<string, mixed>}>  $platforms
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
            'platforms' => array_map(function (array $row): array {
                $entry = [
                    'platform' => $this->normalizePlatform((string) ($row['platform'] ?? '')),
                    'accountId' => (string) ($row['accountId'] ?? $row['account_id'] ?? ''),
                ];

                if (isset($row['content']) && is_string($row['content']) && $row['content'] !== '') {
                    $entry['content'] = $row['content'];
                }

                if (! empty($row['platformSpecificData']) && is_array($row['platformSpecificData'])) {
                    $entry['platformSpecificData'] = $row['platformSpecificData'];
                }

                return $entry;
            }, $platforms),
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
            'twitter',
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
