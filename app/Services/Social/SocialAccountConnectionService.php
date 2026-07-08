<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\Team;
use App\Services\Integrations\ZernioService;

class SocialAccountConnectionService
{
    public function __construct(
        protected ZernioService $zernio,
    ) {}

    public function persistConnection(
        Team $team,
        string $platform,
        string $accountId,
        ?string $username = null,
        ?string $profileId = null,
    ): SocialAccount {
        $platform = $this->normalizePlatform($platform);
        $accountId = trim($accountId);

        return SocialAccount::query()->updateOrCreate(
            [
                'team_id' => $team->id,
                'platform' => $platform,
            ],
            [
                'zernio_account_id' => $accountId,
                'zernio_profile_id' => $profileId ?: trim((string) data_get($this->zernio->zernioSettings($team), 'profile_id', '')) ?: null,
                'platform_username' => $username,
                'connected_at' => now(),
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForTeam(Team $team): array
    {
        return SocialAccount::query()
            ->where('team_id', $team->id)
            ->orderBy('platform')
            ->get()
            ->map(fn (SocialAccount $account): array => $account->toApiArray())
            ->values()
            ->all();
    }

    public function findForTeam(Team $team, string $platform): ?SocialAccount
    {
        return SocialAccount::query()
            ->where('team_id', $team->id)
            ->where('platform', $this->normalizePlatform($platform))
            ->first();
    }

    public function disconnect(Team $team, string $platform): void
    {
        $platform = $this->normalizePlatform($platform);
        $account = $this->findForTeam($team, $platform);

        if ($account === null) {
            return;
        }

        try {
            $this->zernio->disconnectAccount($team, $account->zernio_account_id);
        } catch (\Throwable) {
            // Still remove local record so the UI reflects user intent.
        }

        $account->delete();
    }

    public function disconnectByAccountId(Team $team, string $accountId): void
    {
        $accountId = trim($accountId);

        $account = SocialAccount::query()
            ->where('team_id', $team->id)
            ->where('zernio_account_id', $accountId)
            ->first();

        if ($account === null) {
            return;
        }

        try {
            $this->zernio->disconnectAccount($team, $accountId);
        } catch (\Throwable) {
            // Still remove local record.
        }

        $account->delete();
    }

    /**
     * Facebook sometimes returns connected + username without accountId — resolve from remote list.
     *
     * @return array{id: string, username: ?string}|null
     */
    public function resolveRemoteAccount(Team $team, string $platform, ?string $username = null): ?array
    {
        try {
            $accounts = $this->zernio->listAccounts($team);
        } catch (\Throwable) {
            return null;
        }

        $platform = $this->normalizePlatform($platform);
        $candidates = [];

        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $rowPlatform = $this->normalizePlatform($this->stringify(
                data_get($account, 'platform'),
            ));

            if ($rowPlatform !== $platform) {
                continue;
            }

            $accountId = $this->stringify(data_get($account, '_id') ?? data_get($account, 'id'));

            if ($accountId === '') {
                continue;
            }

            $rowUsername = $this->stringify(
                data_get($account, 'username')
                ?? data_get($account, 'displayName')
                ?? data_get($account, 'name'),
            );

            $candidates[] = [
                'id' => $accountId,
                'username' => $rowUsername !== '' ? $rowUsername : null,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        if ($username !== null && $username !== '') {
            foreach ($candidates as $candidate) {
                if ($candidate['username'] !== null && strcasecmp($candidate['username'], $username) === 0) {
                    return $candidate;
                }
            }
        }

        return $candidates[0];
    }

    protected function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $string = $this->stringify($item);

                if ($string !== '') {
                    return $string;
                }
            }
        }

        return '';
    }

    protected function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return $platform === 'x' ? 'twitter' : $platform;
    }
}
