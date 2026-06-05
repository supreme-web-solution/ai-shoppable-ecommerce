<?php

namespace App\Support;

class ReverbClientConfig
{
    /**
     * Browser-facing Reverb connection settings derived from server .env at request time.
     *
     * @return array{enabled: bool, key: string, host: string, port: int, scheme: string}|null
     */
    public static function forClient(): ?array
    {
        if (config('broadcasting.default') === 'null') {
            return null;
        }

        $reverb = config('broadcasting.connections.reverb');
        $key = $reverb['key'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        $options = is_array($reverb['options'] ?? null) ? $reverb['options'] : [];

        return [
            'enabled' => true,
            'key' => $key,
            'host' => self::resolveClientHost($options['host'] ?? null),
            'port' => (int) ($options['port'] ?? 443),
            'scheme' => ($options['scheme'] ?? 'https') === 'http' ? 'http' : 'https',
        ];
    }

    protected static function resolveClientHost(mixed $host): string
    {
        $host = trim((string) $host);

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            if (is_string($appHost) && $appHost !== '') {
                return $appHost;
            }
        }

        return $host !== '' ? $host : 'localhost';
    }
}
