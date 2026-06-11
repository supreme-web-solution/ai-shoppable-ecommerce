<?php

namespace App\Services\Integrations;

use App\Models\Team;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RestreamService
{
    public function enabled(Team $team): bool
    {
        if (! (bool) config('services.restream.enabled', true)) {
            return false;
        }

        if ($this->usesPlatformStreamKey($team)) {
            return true;
        }

        return $this->clientId() !== ''
            && $this->clientSecret() !== ''
            && ($this->redirectUri() !== '' || $this->managedConfigured());
    }

    public function connected(Team $team): bool
    {
        if ($this->usesPlatformStreamKey($team)) {
            return true;
        }

        if ($this->managedConfigured()) {
            return true;
        }

        $integration = $this->integrationSettings($team);

        return trim((string) ($integration['access_token'] ?? '')) !== ''
            || trim((string) ($integration['refresh_token'] ?? '')) !== '';
    }

    public function ready(Team $team): bool
    {
        if (! $this->enabled($team)) {
            return false;
        }

        if ($this->usesPlatformStreamKey($team)) {
            return true;
        }

        if (! $this->connected($team)) {
            return false;
        }

        try {
            $this->accessToken($team);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function usesPlatformStreamKey(Team $team): bool
    {
        return $this->platformStreamKey($team) !== '';
    }

    public function platformStreamKey(Team $team): string
    {
        return trim((string) config('services.restream.stream_key', ''));
    }

    protected function hasApiAccess(Team $team): bool
    {
        if ($this->managedConfigured()) {
            return true;
        }

        $integration = $this->integrationSettings($team);

        return trim((string) ($integration['access_token'] ?? '')) !== ''
            || trim((string) ($integration['refresh_token'] ?? '')) !== '';
    }

    protected function clientId(): string
    {
        return trim((string) config('services.restream.client_id', ''));
    }

    protected function clientSecret(): string
    {
        return trim((string) config('services.restream.client_secret', ''));
    }

    protected function redirectUri(): string
    {
        return trim((string) config('services.restream.redirect_uri', ''));
    }

    protected function managedAccessToken(): string
    {
        return trim((string) config('services.restream.access_token', ''));
    }

    protected function managedRefreshToken(): string
    {
        return trim((string) config('services.restream.refresh_token', ''));
    }

    protected function managedAccessTokenExpiresAt(): string
    {
        return trim((string) config('services.restream.access_token_expires_at', ''));
    }

    protected function managedRefreshTokenExpiresAt(): string
    {
        return trim((string) config('services.restream.refresh_token_expires_at', ''));
    }

    protected function managedTokenCacheKey(): string
    {
        return 'restream.managed.tokens';
    }

    /**
     * @return array<string, string>
     */
    protected function managedTokenState(): array
    {
        $configured = [
            'access_token' => $this->managedAccessToken(),
            'refresh_token' => $this->managedRefreshToken(),
            'access_token_expires_at' => $this->managedAccessTokenExpiresAt(),
            'refresh_token_expires_at' => $this->managedRefreshTokenExpiresAt(),
            'scope' => '',
        ];
        $cached = Cache::get($this->managedTokenCacheKey());
        if (! is_array($cached)) {
            return $configured;
        }

        return array_merge($configured, array_filter($cached, fn (mixed $value): bool => is_string($value)));
    }

    protected function managedConfigured(): bool
    {
        $state = $this->managedTokenState();

        return trim((string) ($state['access_token'] ?? '')) !== ''
            || trim((string) ($state['refresh_token'] ?? '')) !== '';
    }

    protected function oauthBaseUrl(): string
    {
        return rtrim((string) config('services.restream.oauth_base_url', 'https://api.restream.io'), '/');
    }

    protected function apiBaseUrl(): string
    {
        return rtrim((string) config('services.restream.base_url', 'https://api.restream.io'), '/');
    }

    protected function integrationSettings(Team $team): array
    {
        $settings = is_array($team->settings) ? $team->settings : [];
        $integration = data_get($settings, 'integrations.restream', []);

        return is_array($integration) ? $integration : [];
    }

    protected function persistIntegrationSettings(Team $team, array $patch): void
    {
        $settings = is_array($team->settings) ? $team->settings : [];
        $existing = $this->integrationSettings($team);

        data_set(
            $settings,
            'integrations.restream',
            array_filter(
                array_merge($existing, $patch),
                static fn (mixed $value): bool => $value !== null,
            ),
        );

        $team->forceFill(['settings' => $settings])->save();
        $team->refresh();
    }

    protected function stateCacheKey(string $state): string
    {
        return 'restream.oauth.state.'.$state;
    }

    public function authorizeUrl(Team $team, int $userId): string
    {
        abort_unless($this->enabled($team), 422, 'Restream credentials are missing in environment.');
        abort_unless($this->redirectUri() !== '', 422, 'Restream redirect URI is missing in environment.');

        $state = (string) Str::uuid();
        Cache::put($this->stateCacheKey($state), [
            'team_id' => $team->id,
            'user_id' => $userId,
        ], now()->addMinutes(10));

        return $this->oauthBaseUrl().'/login?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    /**
     * @return array{team_id:int,user_id:int}|null
     */
    public function consumeAuthorizationState(string $state): ?array
    {
        $payload = Cache::pull($this->stateCacheKey($state));

        if (! is_array($payload)) {
            return null;
        }

        $teamId = (int) ($payload['team_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);

        if ($teamId <= 0 || $userId <= 0) {
            return null;
        }

        return [
            'team_id' => $teamId,
            'user_id' => $userId,
        ];
    }

    public function completeAuthorization(Team $team, string $code, string $scope = ''): void
    {
        $tokens = $this->oauthTokenRequest([
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);

        $this->persistManagedTokens($tokens, $scope);
        $this->persistTokens($team, $tokens, $scope);
    }

    public function disconnect(Team $team): void
    {
        $this->persistIntegrationSettings($team, [
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'refresh_token_expires_at' => null,
            'scope' => null,
            'connected_at' => null,
            'last_error' => null,
        ]);
    }

    protected function oauthTokenRequest(array $form): array
    {
        $response = Http::timeout(35)
            ->asForm()
            ->withBasicAuth($this->clientId(), $this->clientSecret())
            ->post($this->oauthBaseUrl().'/oauth/token', $form);

        if (! $response->successful()) {
            $message = data_get($response->json(), 'error.message')
                ?? data_get($response->json(), 'message')
                ?? 'Restream authorization failed.';

            throw new \RuntimeException($message.' (HTTP '.$response->status().')');
        }

        return $response->json() ?? [];
    }

    protected function refreshAccessToken(Team $team): string
    {
        $integration = $this->integrationSettings($team);
        $refreshToken = trim((string) ($integration['refresh_token'] ?? ''));

        if ($refreshToken === '') {
            throw new \RuntimeException('Restream refresh token is missing. Configure your Restream integration credentials.');
        }

        $tokens = $this->oauthTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        $this->persistTokens($team, $tokens, (string) ($integration['scope'] ?? ''));
        $refreshed = $this->integrationSettings($team);

        return trim((string) ($refreshed['access_token'] ?? ''));
    }

    protected function refreshManagedAccessToken(): string
    {
        $state = $this->managedTokenState();
        $refreshToken = trim((string) ($state['refresh_token'] ?? ''));

        if ($refreshToken === '') {
            throw new \RuntimeException('Managed Restream refresh token is missing. Set RESTREAM_REFRESH_TOKEN in environment.');
        }

        $tokens = $this->oauthTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        $this->persistManagedTokens($tokens, (string) ($state['scope'] ?? ''));
        $refreshed = $this->managedTokenState();

        return trim((string) ($refreshed['access_token'] ?? ''));
    }

    protected function persistTokens(Team $team, array $tokens, string $scope = ''): void
    {
        $accessToken = trim((string) ($tokens['access_token'] ?? $tokens['accessToken'] ?? ''));
        $refreshToken = trim((string) ($tokens['refresh_token'] ?? $tokens['refreshToken'] ?? ''));
        $accessTtl = (int) ($tokens['expires_in'] ?? $tokens['accessTokenExpiresIn'] ?? 3600);
        $refreshTtl = (int) ($tokens['refreshTokenExpiresIn'] ?? 31536000);

        if ($accessToken === '' || $refreshToken === '') {
            throw new \RuntimeException('Restream did not return a valid token pair.');
        }

        $this->persistIntegrationSettings($team, [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'access_token_expires_at' => now()->addSeconds(max(60, $accessTtl - 10))->toIso8601String(),
            'refresh_token_expires_at' => now()->addSeconds(max(60, $refreshTtl - 10))->toIso8601String(),
            'scope' => trim((string) ($tokens['scope'] ?? $scope)),
            'connected_at' => now()->toIso8601String(),
            'last_error' => null,
        ]);
    }

    protected function accessToken(Team $team): string
    {
        $integration = $this->integrationSettings($team);
        $accessToken = trim((string) ($integration['access_token'] ?? ''));
        $expiresAt = trim((string) ($integration['access_token_expires_at'] ?? ''));

        if ($accessToken !== '') {
            try {
                if ($expiresAt !== '' && Carbon::parse($expiresAt)->lte(now()->addSeconds(30))) {
                    return $this->refreshAccessToken($team);
                }
            } catch (\Throwable) {
                return $this->refreshAccessToken($team);
            }

            return $accessToken;
        }

        $managed = $this->managedTokenState();
        $managedAccessToken = trim((string) ($managed['access_token'] ?? ''));
        $managedExpiresAt = trim((string) ($managed['access_token_expires_at'] ?? ''));

        if ($managedAccessToken === '') {
            if (trim((string) ($managed['refresh_token'] ?? '')) !== '') {
                return $this->refreshManagedAccessToken();
            }

            throw new \RuntimeException('Live streaming is not configured yet.');
        }

        if ($managedExpiresAt !== '') {
            try {
                if (Carbon::parse($managedExpiresAt)->lte(now()->addSeconds(30))) {
                    return $this->refreshManagedAccessToken();
                }
            } catch (\Throwable) {
                return $this->refreshManagedAccessToken();
            }
        }

        return $managedAccessToken;
    }

    protected function persistManagedTokens(array $tokens, string $scope = ''): void
    {
        $existing = $this->managedTokenState();
        $accessToken = trim((string) ($tokens['access_token'] ?? $tokens['accessToken'] ?? ''));
        $refreshToken = trim((string) ($tokens['refresh_token'] ?? $tokens['refreshToken'] ?? ($existing['refresh_token'] ?? '')));
        $accessTtl = (int) ($tokens['expires_in'] ?? $tokens['accessTokenExpiresIn'] ?? 3600);
        $refreshTtl = (int) ($tokens['refreshTokenExpiresIn'] ?? 31536000);

        if ($accessToken === '') {
            throw new \RuntimeException('Restream did not return a valid managed access token.');
        }

        Cache::forever($this->managedTokenCacheKey(), [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'access_token_expires_at' => now()->addSeconds(max(60, $accessTtl - 10))->toIso8601String(),
            'refresh_token_expires_at' => $refreshToken !== ''
                ? now()->addSeconds(max(60, $refreshTtl - 10))->toIso8601String()
                : ($existing['refresh_token_expires_at'] ?? ''),
            'scope' => trim((string) ($tokens['scope'] ?? $scope)),
        ]);
    }

    public function createStream(Team $team, array $payload): array
    {
        if ($this->usesPlatformStreamKey($team)) {
            return $this->createPlatformStream($team);
        }

        $streamData = $this->request($team, 'GET', '/v2/user/streamKey');
        $streamKey = trim((string) ($streamData['streamKey'] ?? ''));

        if ($streamKey === '') {
            throw new \RuntimeException('Streaming provider did not return a stream key.');
        }

        $ingestInfo = $this->resolveIngestServer($team);
        $channels = $this->listChannels($team);

        $preferredPlayback = collect($channels)
            ->first(fn (array $channel): bool => (bool) ($channel['active'] ?? false))
            ?? ($channels[0] ?? null);

        return [
            'id' => 'restream-'.$team->id.'-'.now()->timestamp,
            'streamKey' => $streamKey,
            'playbackUrl' => is_array($preferredPlayback) ? ($preferredPlayback['embed_url'] ?: $preferredPlayback['url']) : null,
            'ingestUrl' => $ingestInfo['ingest_url'] ?? 'rtmp://live.restream.io/live',
            'ingestId' => $ingestInfo['ingest_id'] ?? null,
            'srtUrl' => $streamData['srtUrl'] ?? null,
            'channels' => $channels,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePlayerUrl(): ?string
    {
        $playerUrl = trim((string) config('services.restream.player_url', ''));

        if ($playerUrl !== '') {
            return $playerUrl;
        }

        $playerToken = trim((string) config('services.restream.player_token', ''));

        if ($playerToken !== '') {
            return 'https://player.restream.io/?token='.$playerToken;
        }

        return null;
    }

    protected function createPlatformStream(Team $team): array
    {
        $streamKey = $this->platformStreamKey($team);
        $playerUrl = $this->resolvePlayerUrl() ?? '';
        $ingestUrl = trim((string) config('services.restream.ingest_url', 'rtmp://live.restream.io/live'));
        $srtUrl = trim((string) config('services.restream.srt_url', ''));

        return [
            'id' => 'platform-'.$team->id,
            'streamKey' => $streamKey,
            'playbackUrl' => $playerUrl !== '' ? $playerUrl : null,
            'ingestUrl' => $ingestUrl !== '' ? $ingestUrl : 'rtmp://live.restream.io/live',
            'ingestId' => null,
            'srtUrl' => $srtUrl !== '' ? $srtUrl : null,
            'channels' => [],
        ];
    }

    public function getStream(Team $team, string $streamId): array
    {
        if ($this->usesPlatformStreamKey($team) && ! $this->hasApiAccess($team)) {
            return [
                'id' => $streamId,
                'isActive' => false,
                'lastSeen' => null,
                'sourceSegments' => 0,
            ];
        }

        $channels = $this->listChannels($team);
        $activeCount = collect($channels)->where('active', true)->count();

        return [
            'id' => $streamId,
            'isActive' => $activeCount > 0,
            'lastSeen' => now()->toIso8601String(),
            'sourceSegments' => $activeCount,
        ];
    }

    public function updateStream(Team $team, string $streamId, array $payload): array
    {
        return $this->createStream($team, $payload);
    }

    public function addMultistreamTarget(Team $team, string $streamId, array $target): array
    {
        if ($this->usesPlatformStreamKey($team) && ! $this->hasApiAccess($team)) {
            throw new \RuntimeException('Simulcast targets require API access. Configure simulcast destinations in your Restream dashboard instead.');
        }

        $rawUrl = trim((string) data_get($target, 'spec.url', ''));
        $displayName = trim((string) data_get($target, 'spec.name', ''));

        if ($rawUrl === '') {
            throw new \RuntimeException('Target URL is required.');
        }

        $streamUrl = $rawUrl;
        $streamKey = '';

        if (preg_match('~^(rtmps?://[^/]+/[^/]+)/(.+)$~i', $rawUrl, $matches) === 1) {
            $streamUrl = $matches[1];
            $streamKey = $matches[2];
        }

        return $this->request($team, 'POST', '/v2/user/channels', [
            'platformId' => 29,
            'streamUrl' => $streamUrl,
            'streamKey' => $streamKey !== '' ? $streamKey : null,
            'displayName' => $displayName !== '' ? $displayName : null,
        ]);
    }

    /**
     * @return array{ingest_id:int|null,ingest_url:string}
     */
    public function resolveIngestServer(Team $team): array
    {
        $selected = $this->request($team, 'GET', '/v2/user/ingest');
        $ingestId = isset($selected['ingestId']) ? (int) $selected['ingestId'] : null;
        $servers = $this->ingestServers();
        $defaultRtmp = 'rtmp://live.restream.io/live';

        if ($ingestId === null) {
            return ['ingest_id' => null, 'ingest_url' => $defaultRtmp];
        }

        $match = collect($servers)->first(fn (array $server): bool => (int) ($server['id'] ?? 0) === $ingestId);
        $ingestUrl = trim((string) ($match['rtmpUrl'] ?? ''));

        return [
            'ingest_id' => $ingestId,
            'ingest_url' => $ingestUrl !== '' ? $ingestUrl : $defaultRtmp,
        ];
    }

    /**
     * @return array<int, array{id:int,display_name:string,url:string,embed_url:string,active:bool}>
     */
    public function listChannels(Team $team): array
    {
        if ($this->usesPlatformStreamKey($team) && ! $this->hasApiAccess($team)) {
            return [];
        }

        $channels = $this->request($team, 'GET', '/v2/user/channel/all');

        if (! is_array($channels) || Arr::isAssoc($channels)) {
            $channels = (array) data_get($this->request($team, 'GET', '/v2/user/channels'), 'channels', []);
        }

        return collect($channels)
            ->filter(fn (mixed $channel): bool => is_array($channel))
            ->map(function (array $channel): array {
                return [
                    'id' => (int) ($channel['id'] ?? 0),
                    'display_name' => (string) ($channel['displayName'] ?? $channel['name'] ?? 'Channel'),
                    'url' => trim((string) ($channel['url'] ?? $channel['channelUrl'] ?? '')),
                    'embed_url' => trim((string) ($channel['embedUrl'] ?? '')),
                    'active' => (bool) ($channel['active'] ?? false),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,rtmpUrl:string,name:string}>
     */
    protected function ingestServers(): array
    {
        return Cache::remember('restream.ingest.servers', now()->addMinutes(10), function (): array {
            $response = Http::timeout(20)
                ->acceptJson()
                ->get($this->apiBaseUrl().'/v2/server/all');

            if (! $response->successful()) {
                return [];
            }

            $payload = $response->json();

            return is_array($payload) ? $payload : [];
        });
    }

    protected function request(Team $team, string $method, string $path, array $payload = []): array
    {
        $token = $this->accessToken($team);
        $http = Http::timeout(35)->withToken($token)->acceptJson();
        $url = $this->apiBaseUrl().$path;
        $verb = strtoupper($method);

        $response = match ($verb) {
            'GET' => $http->get($url, $payload),
            'POST' => $http->post($url, $payload),
            'PUT' => $http->put($url, $payload),
            'PATCH' => $http->patch($url, $payload),
            'DELETE' => $http->delete($url, $payload),
            default => throw new \InvalidArgumentException('Unsupported HTTP method ['.$verb.'].'),
        };

        if (! $response->successful()) {
            Log::warning('Restream API request failed', [
                'team_id' => $team->id,
                'method' => $verb,
                'path' => $path,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 800),
            ]);

            $message = data_get($response->json(), 'error.message')
                ?? data_get($response->json(), 'message')
                ?? data_get($response->json(), 'errors.0')
                ?? 'Streaming provider request failed.';

            $this->persistIntegrationSettings($team, [
                'last_error' => $message,
            ]);

            throw new \RuntimeException($message.' (HTTP '.$response->status().')');
        }

        return $response->json() ?? [];
    }
}
