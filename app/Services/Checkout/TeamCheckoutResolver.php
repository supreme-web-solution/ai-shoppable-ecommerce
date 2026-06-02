<?php

namespace App\Services\Checkout;

use App\Models\Team;

class TeamCheckoutResolver
{
    /**
     * @return array{mode: 'native'|'external', provider: ?string}
     */
    public function resolve(Team $team, string $requestedMode, ?string $requestedProvider = null): array
    {
        if ($requestedMode === 'hybrid') {
            return $this->resolveHybrid($team, $requestedProvider);
        }

        if ($requestedMode === 'external') {
            $provider = $this->resolveProvider($team, $requestedProvider);

            if ($provider !== null && $this->isProviderReady($team, $provider)) {
                return ['mode' => 'external', 'provider' => $provider];
            }

            return ['mode' => 'native', 'provider' => null];
        }

        return ['mode' => 'native', 'provider' => null];
    }

    /**
     * @return array{mode: 'native'|'external', provider: ?string}
     */
    private function resolveHybrid(Team $team, ?string $requestedProvider): array
    {
        $teamMode = $team->checkout_mode ?: 'native';
        $externalProvider = $this->resolveProvider($team, $requestedProvider);
        $externalReady = $externalProvider !== null && $this->isProviderReady($team, $externalProvider);
        $nativeReady = $this->isNativeReady($team);

        if ($teamMode === 'external' && $externalReady) {
            return ['mode' => 'external', 'provider' => $externalProvider];
        }

        if ($teamMode === 'native' && $nativeReady) {
            return ['mode' => 'native', 'provider' => null];
        }

        if ($externalReady) {
            return ['mode' => 'external', 'provider' => $externalProvider];
        }

        if ($nativeReady) {
            return ['mode' => 'native', 'provider' => null];
        }

        return ['mode' => 'native', 'provider' => null];
    }

    public function isNativeReady(Team $team): bool
    {
        return $this->activeNativeProvider($team) !== null;
    }

    public function activeNativeProvider(Team $team): ?string
    {
        foreach (['stripe', 'paypal'] as $provider) {
            if ($this->isNativeProviderReady($team, $provider)) {
                return $provider;
            }
        }

        return null;
    }

    public function isProviderReady(Team $team, string $provider): bool
    {
        if (! in_array($provider, ['shopify', 'woocommerce'], true)) {
            return false;
        }

        $settings = (array) data_get($team->settings, "integrations.{$provider}", []);

        if (! (bool) ($settings['enabled'] ?? false)) {
            return false;
        }

        return match ($provider) {
            'shopify' => trim((string) ($settings['shop_url'] ?? '')) !== ''
                && (
                    (trim((string) ($settings['client_id'] ?? '')) !== ''
                        && trim((string) ($settings['client_secret'] ?? '')) !== '')
                    || trim((string) ($settings['access_token'] ?? '')) !== ''
                ),
            'woocommerce' => trim((string) ($settings['site_url'] ?? '')) !== ''
                && trim((string) ($settings['consumer_key'] ?? '')) !== ''
                && trim((string) ($settings['consumer_secret'] ?? '')) !== '',
        };
    }

    public function activeExternalProvider(Team $team): ?string
    {
        $preferred = $team->external_provider !== 'none' ? $team->external_provider : null;

        if ($preferred !== null && $this->isProviderReady($team, $preferred)) {
            return $preferred;
        }

        foreach (['shopify', 'woocommerce'] as $provider) {
            if ($this->isProviderReady($team, $provider)) {
                return $provider;
            }
        }

        return null;
    }

    public function isNativeProviderReady(Team $team, string $provider): bool
    {
        if (! in_array($provider, ['stripe', 'paypal'], true)) {
            return false;
        }

        $settings = (array) data_get($team->settings, "integrations.{$provider}", []);

        if (! (bool) ($settings['enabled'] ?? false)) {
            return false;
        }

        return match ($provider) {
            'stripe' => trim((string) ($settings['secret_key'] ?? '')) !== '',
            'paypal' => trim((string) ($settings['client_id'] ?? '')) !== ''
                && trim((string) ($settings['client_secret'] ?? '')) !== '',
        };
    }

    private function resolveProvider(Team $team, ?string $requestedProvider): ?string
    {
        if ($requestedProvider !== null && $requestedProvider !== 'none') {
            return $requestedProvider;
        }

        if ($team->external_provider !== 'none') {
            return $team->external_provider;
        }

        return $this->activeExternalProvider($team);
    }
}
