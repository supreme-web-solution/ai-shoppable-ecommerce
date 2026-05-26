<?php

namespace App\Support;

use App\Models\Embed;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TeamApiAuthorizer
{
    public function assertPlayerAccess(Request $request, int $teamId, ?Embed $embed = null): void
    {
        if ($this->hasUserTeamAccess($request, $teamId)) {
            return;
        }

        if ($this->hasTokenAccess($request, $teamId, 'player')) {
            return;
        }

        if ($this->hasEmbedAccess($request, $teamId, $embed)) {
            return;
        }

        abort(403, 'Player access is not authorized for this team.');
    }

    public function assertAnalyticsIngestAccess(Request $request, int $teamId): void
    {
        if ($this->hasUserTeamAccess($request, $teamId)) {
            return;
        }

        if ($this->hasTokenAccess($request, $teamId, 'analytics:ingest')) {
            return;
        }

        if ($this->hasEmbedAccess($request, $teamId)) {
            return;
        }

        abort(403, 'Analytics ingestion is not authorized for this team.');
    }

    protected function hasUserTeamAccess(Request $request, int $teamId): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        return $user->team_id === $teamId || $user->teams()->whereKey($teamId)->exists();
    }

    protected function hasTokenAccess(Request $request, int $teamId, string $scope): bool
    {
        $rawToken = trim((string) $request->bearerToken());
        if ($rawToken === '') {
            return false;
        }

        $token = PersonalAccessToken::findToken($rawToken);
        abort_unless($token, 401, 'Invalid API token.');
        abort_if($token->expires_at && $token->expires_at->isPast(), 401, 'API token has expired.');

        $abilities = $token->abilities ?? [];
        $ability = "team:{$teamId}:{$scope}";

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    protected function hasEmbedAccess(Request $request, int $teamId, ?Embed $resolvedEmbed = null): bool
    {
        $embed = $resolvedEmbed;

        if (! $embed) {
            $embedSlug = trim((string) ($request->header('X-Embed-Slug') ?: $request->input('embed_slug', '')));
            if ($embedSlug === '') {
                return false;
            }

            $embed = Embed::query()
                ->where('slug', $embedSlug)
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->first();
        }

        if (! $embed || $embed->team_id !== $teamId) {
            return false;
        }

        return $this->hostAllowed($embed, $request);
    }

    protected function hostAllowed(Embed $embed, Request $request): bool
    {
        $allowedDomains = collect($embed->allowed_domains ?? [])
            ->map(static fn (mixed $domain): string => strtolower(trim((string) $domain)))
            ->filter()
            ->values();

        if ($allowedDomains->isEmpty()) {
            return true;
        }

        $originHost = parse_url((string) $request->headers->get('origin'), PHP_URL_HOST);
        $refererHost = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);
        $requestHost = is_string($originHost) && $originHost !== '' ? $originHost : $refererHost;

        if (! is_string($requestHost) || $requestHost === '') {
            return false;
        }

        return $allowedDomains->contains(strtolower($requestHost));
    }
}
