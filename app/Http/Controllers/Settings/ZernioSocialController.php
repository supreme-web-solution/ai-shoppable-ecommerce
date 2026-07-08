<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Integrations\ZernioService;
use App\Services\Social\SocialAccountConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ZernioSocialController extends Controller
{
    private const PLATFORM_LABELS = [
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'linkedin' => 'LinkedIn',
        'twitter' => 'X / Twitter',
    ];

    public function __construct(
        protected ZernioService $zernio,
        protected SocialAccountConnectionService $connections,
    ) {}

    public function connectRedirect(Request $request, string $platform): RedirectResponse
    {
        $this->assertEnabled();

        $platform = $this->normalizeRoutePlatform($platform);
        $team = $this->resolveTeam($request);
        $origin = $this->requestOrigin($request);
        $callbackUrl = $this->oauthCallbackUrl($request, $platform);

        Log::info('Zernio OAuth: connect started', [
            'user_id' => $request->user()?->id,
            'team_id' => $team->id,
            'platform' => $platform,
            'request_host' => $request->getHost(),
            'request_origin' => $origin,
            'app_url' => config('app.url'),
            'callback_url' => $callbackUrl,
            'session_id' => $request->session()->getId(),
        ]);

        $request->session()->put('zernio_oauth_team_id', $team->id);
        $request->session()->put('zernio_oauth_return_origin', $origin);
        $request->session()->put('zernio_oauth_platform', $platform);

        try {
            $authUrl = $this->zernio->connectUrl($team, $platform, $callbackUrl);

            Log::info('Zernio OAuth: redirecting user to provider', [
                'team_id' => $team->id,
                'platform' => $platform,
                'auth_url' => $authUrl,
                'callback_url_sent' => $callbackUrl,
            ]);

            return redirect()->away($authUrl);
        } catch (\Throwable $exception) {
            Log::error('Zernio OAuth: failed to start connect', [
                'team_id' => $team->id,
                'platform' => $platform,
                'callback_url' => $callbackUrl,
                'error' => $exception->getMessage(),
            ]);

            return $this->failureRedirect($request, $exception->getMessage());
        }
    }

    public function callback(Request $request, string $platform): RedirectResponse
    {
        $this->assertEnabled();

        $platform = $this->normalizeRoutePlatform($platform);
        $teamId = (int) $request->session()->pull('zernio_oauth_team_id');
        $returnOrigin = (string) $request->session()->pull('zernio_oauth_return_origin', $this->requestOrigin($request));
        $startedPlatform = (string) $request->session()->pull('zernio_oauth_platform', $platform);

        Log::info('Zernio OAuth: callback received', [
            'user_id' => $request->user()?->id,
            'platform' => $platform,
            'started_platform' => $startedPlatform,
            'team_id_from_session' => $teamId,
            'request_host' => $request->getHost(),
            'request_origin' => $this->requestOrigin($request),
            'return_origin' => $returnOrigin,
            'session_id' => $request->session()->getId(),
            'query' => $request->query(),
        ]);

        if ($teamId <= 0) {
            Log::warning('Zernio OAuth: callback missing team session', [
                'platform' => $platform,
                'request_host' => $request->getHost(),
                'hint' => 'APP_URL host must match the browser URL (use localhost consistently, not 127.0.0.1).',
            ]);

            return $this->failureRedirect(
                $request,
                'Social connect session expired. Use the same host you started from (e.g. localhost, not 127.0.0.1).',
                $returnOrigin,
            );
        }

        $team = Team::query()->find($teamId);

        if ($team === null || ! $request->user()?->teams()->where('teams.id', $team->id)->exists()) {
            Log::warning('Zernio OAuth: callback team verification failed', [
                'team_id' => $teamId,
                'user_id' => $request->user()?->id,
            ]);

            return $this->failureRedirect($request, 'Could not verify your team for this social connection.', $returnOrigin);
        }

        // Flow B: Zernio already exchanged the OAuth code server-side (Instagram uses this).
        $preConnected = $this->handlePreConnectedCallback($request, $team, $returnOrigin);
        if ($preConnected !== null) {
            return $preConnected;
        }

        // Flow A: raw OAuth code + state — exchange with Zernio from our server.
        $code = $request->query('code');
        $state = $request->query('state');

        if (is_string($code) && $code !== '' && is_string($state) && $state !== '') {
            try {
                $result = $this->zernio->completeOAuthConnection($team, $platform, $code, $state);

                $this->persistFromOAuthResult($team, $platform, $result);

                Log::info('Zernio OAuth: connection completed (Flow A)', [
                    'team_id' => $teamId,
                    'platform' => $platform,
                    'account_id' => data_get($result, 'account._id') ?? data_get($result, 'account.id'),
                    'account_username' => data_get($result, 'account.username') ?? data_get($result, 'account.displayName'),
                    'response_keys' => array_keys($result),
                ]);
            } catch (\Throwable $exception) {
                Log::error('Zernio OAuth: code exchange failed', [
                    'team_id' => $teamId,
                    'platform' => $platform,
                    'error' => $exception->getMessage(),
                ]);

                return $this->failureRedirect($request, $exception->getMessage(), $returnOrigin);
            }

            return $this->successRedirect($teamId, $platform, $returnOrigin);
        }

        // Flow C: confirm via remote API and save locally (last resort).
        if ($this->syncAndPersistPlatform($team, $platform)) {
            Log::info('Zernio OAuth: connection confirmed via account sync (Flow C)', [
                'team_id' => $teamId,
                'platform' => $platform,
            ]);

            return $this->successRedirect($teamId, $platform, $returnOrigin);
        }

        $error = $request->query('error_description') ?? $request->query('error');

        Log::warning('Zernio OAuth: callback could not be handled', [
            'team_id' => $teamId,
            'platform' => $platform,
            'error' => $error,
            'query' => $request->query(),
        ]);

        return $this->failureRedirect(
            $request,
            is_string($error) && $error !== ''
                ? $error
                : 'OAuth was cancelled or did not complete.',
            $returnOrigin,
        );
    }

    protected function handlePreConnectedCallback(Request $request, Team $team, string $returnOrigin): ?RedirectResponse
    {
        $connected = trim((string) ($request->query('connected') ?? $request->query('platform') ?? ''));
        $accountId = $this->stringifyQueryValue(
            $request->query('accountId')
            ?? $request->query('account_id')
            ?? $request->query('account')
        );

        if ($connected === '') {
            return null;
        }

        $connectedPlatform = $this->normalizeConnectedPlatform($connected);
        $profileId = trim((string) ($request->query('profileId') ?? $request->query('profile_id') ?? ''));
        $teamProfileId = trim((string) data_get($this->zernio->zernioSettings($team), 'profile_id', ''));
        $username = $this->stringifyQueryValue($request->query('username') ?? $request->query('user'));
        $username = $username !== '' ? $username : null;

        if ($accountId === '') {
            $resolved = $this->connections->resolveRemoteAccount($team, $connectedPlatform, $username);

            if ($resolved === null) {
                Log::warning('Zernio OAuth: pre-connected without accountId, remote resolve failed', [
                    'team_id' => $team->id,
                    'platform' => $connectedPlatform,
                    'username' => $username,
                ]);

                return null;
            }

            $accountId = $resolved['id'];
            $username = $username ?? $resolved['username'];

            Log::info('Zernio OAuth: resolved accountId from remote list', [
                'team_id' => $team->id,
                'platform' => $connectedPlatform,
                'account_id' => $accountId,
            ]);
        }

        if ($profileId !== '' && $teamProfileId !== '' && $profileId !== $teamProfileId) {
            Log::warning('Zernio OAuth: pre-connected profile mismatch', [
                'team_id' => $team->id,
                'expected_profile_id' => $teamProfileId,
                'received_profile_id' => $profileId,
                'account_id' => $accountId,
            ]);

            return $this->failureRedirect(
                $request,
                'Connected account does not match this team\'s Zernio profile.',
                $returnOrigin,
            );
        }

        Log::info('Zernio OAuth: connection completed (Flow B)', [
            'team_id' => $team->id,
            'platform' => $connectedPlatform,
            'account_id' => $accountId,
            'profile_id' => $profileId !== '' ? $profileId : $teamProfileId,
            'username' => $username,
            'connect_token_present' => is_string($request->query('connect_token')) && $request->query('connect_token') !== '',
        ]);

        $this->connections->persistConnection(
            $team,
            $connectedPlatform,
            $accountId,
            $username,
            $profileId !== '' ? $profileId : ($teamProfileId !== '' ? $teamProfileId : null),
        );

        return $this->successRedirect($team->id, $connectedPlatform, $returnOrigin, $username);
    }

    protected function syncAndPersistPlatform(Team $team, string $platform): bool
    {
        try {
            return $this->persistResolvedRemoteAccount($team, $platform);
        } catch (\Throwable $exception) {
            Log::warning('Zernio OAuth: could not list accounts for Flow C', [
                'team_id' => $team->id,
                'platform' => $platform,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    protected function persistResolvedRemoteAccount(Team $team, string $platform): bool
    {
        $resolved = $this->connections->resolveRemoteAccount($team, $platform);

        if ($resolved === null) {
            return false;
        }

        $this->connections->persistConnection(
            $team,
            $platform,
            $resolved['id'],
            $resolved['username'],
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function persistFromOAuthResult(Team $team, string $platform, array $result): void
    {
        $accountId = (string) (data_get($result, 'account._id') ?? data_get($result, 'account.id') ?? '');

        if ($accountId === '') {
            return;
        }

        $username = data_get($result, 'account.username') ?? data_get($result, 'account.displayName');
        $profileId = data_get($result, 'account.profileId') ?? data_get($result, 'account.profile_id');

        $this->connections->persistConnection(
            $team,
            $platform,
            $accountId,
            is_string($username) ? $username : null,
            is_string($profileId) ? $profileId : null,
        );
    }

    protected function successRedirect(
        int $teamId,
        string $platform,
        string $returnOrigin,
        ?string $username = null,
    ): RedirectResponse {
        $label = self::PLATFORM_LABELS[$platform] ?? ucfirst($platform);
        $message = $username
            ? "{$label} connected as @{$username}."
            : "{$label} connected successfully.";

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
        ]);

        $returnUrl = $this->returnUrl($returnOrigin);

        Log::info('Zernio OAuth: redirecting user back to app', [
            'team_id' => $teamId,
            'platform' => $platform,
            'return_url' => $returnUrl,
            'username' => $username,
        ]);

        return redirect()->to($returnUrl);
    }

    protected function normalizeConnectedPlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return $platform === 'x' ? 'twitter' : $platform;
    }

    protected function stringifyQueryValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $string = $this->stringifyQueryValue($item);

                if ($string !== '') {
                    return $string;
                }
            }
        }

        return '';
    }

    protected function assertEnabled(): void
    {
        abort_unless($this->zernio->enabled(), 404, 'Zernio integration is disabled.');
    }

    protected function resolveTeam(Request $request): Team
    {
        $teamId = (int) $request->integer('team_id');
        $team = Team::query()->findOrFail($teamId);
        $user = $request->user();
        abort_unless($user && $user->teams()->where('teams.id', $team->id)->exists(), 403);

        return $team;
    }

    protected function normalizeRoutePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        if ($platform === 'x') {
            return 'twitter';
        }

        abort_unless(in_array($platform, $this->zernio->supportedPlatforms(), true), 404);

        return $platform;
    }

    protected function routeSlugForPlatform(string $platform): string
    {
        return $platform === 'twitter' ? 'twitter' : $platform;
    }

    protected function requestOrigin(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }

    protected function oauthCallbackUrl(Request $request, string $platform): string
    {
        $slug = $this->routeSlugForPlatform($platform);

        return $this->requestOrigin($request).'/settings/integrations/zernio/'.$slug.'/callback';
    }

    protected function returnUrl(string $origin): string
    {
        return rtrim($origin, '/').'/dashboard';
    }

    protected function failureRedirect(Request $request, string $message, ?string $returnOrigin = null): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'error',
            'message' => $message,
        ]);

        $origin = $returnOrigin ?? $this->requestOrigin($request);

        Log::info('Zernio OAuth: failure redirect', [
            'message' => $message,
            'return_url' => $this->returnUrl($origin),
        ]);

        return redirect()->to($this->returnUrl($origin));
    }
}
