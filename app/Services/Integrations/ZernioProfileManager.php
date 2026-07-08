<?php

namespace App\Services\Integrations;

use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ZernioProfileManager
{
    public function __construct(
        protected ZernioService $zernio,
    ) {}

    public function withProfile(Team $team, callable $callback): mixed
    {
        $profileId = $this->ensureForTeam($team);

        try {
            return $callback($profileId);
        } catch (ZernioApiException $e) {
            if (! $e->isStaleProfileError()) {
                throw $e;
            }

            Log::warning('ZernioProfileManager: stale profile detected, recreating', [
                'team_id' => $team->id,
                'stale_profile_id' => $profileId,
                'error' => $e->getMessage(),
            ]);

            $profileId = $this->recreateForTeam($team);

            return $callback($profileId);
        }
    }

    public function ensureForTeam(Team $team): string
    {
        $profileId = $this->readProfileId($team);

        if ($profileId !== '') {
            return $profileId;
        }

        try {
            return $this->createAndStoreProfile($team);
        } catch (ZernioApiException $e) {
            if (! $e->isDuplicateProfileError()) {
                throw $e;
            }

            $adopted = $this->adoptExistingProfileForTeam($team);

            if ($adopted !== null) {
                return $adopted;
            }

            throw $e;
        }
    }

    public function recreateForTeam(Team $team): string
    {
        $staleId = $this->readProfileId($team);
        $this->clearProfileId($team);

        $freshTeam = $team->fresh() ?? $team;
        $profileId = $this->ensureForTeam($freshTeam);

        Log::info('ZernioProfileManager: recreated profile', [
            'team_id' => $team->id,
            'stale_profile_id' => $staleId,
            'zernio_profile_id' => $profileId,
        ]);

        return $profileId;
    }

    public function readProfileId(Team $team): string
    {
        return trim((string) data_get($this->zernio->zernioSettings($team), 'profile_id', ''));
    }

    protected function createAndStoreProfile(Team $team): string
    {
        $response = $this->zernio->createProfile(
            Str::limit($team->name.' — '.config('app.name'), 120),
            'Social publishing profile for team #'.$team->id,
        );

        $profileId = (string) (data_get($response, 'profile._id') ?? data_get($response, '_id') ?? '');

        if ($profileId === '') {
            throw new ZernioApiException('Zernio did not return a profile id.');
        }

        $this->storeProfileId($team, $profileId);

        return $profileId;
    }

    protected function adoptExistingProfileForTeam(Team $team): ?string
    {
        $profiles = $this->zernio->listProfiles();
        $needle = Str::lower($team->name);

        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $name = Str::lower((string) (data_get($profile, 'name') ?? ''));
            $profileId = (string) (data_get($profile, '_id') ?? data_get($profile, 'id') ?? '');

            if ($profileId === '' || $name === '') {
                continue;
            }

            if (str_contains($name, $needle) || str_contains($needle, $name)) {
                $this->storeProfileId($team, $profileId);

                Log::info('ZernioProfileManager: adopted existing profile', [
                    'team_id' => $team->id,
                    'zernio_profile_id' => $profileId,
                ]);

                return $profileId;
            }
        }

        return null;
    }

    protected function storeProfileId(Team $team, string $profileId): void
    {
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
    }

    protected function clearProfileId(Team $team): void
    {
        $settings = (array) $team->settings;
        data_set($settings, 'integrations.zernio.profile_id', null);
        data_set($settings, 'integrations.zernio.profile_created_at', null);
        $team->forceFill(['settings' => $settings])->save();
    }
}
