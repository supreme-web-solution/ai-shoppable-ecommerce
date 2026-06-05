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
        $scheme = self::resolveClientScheme($options['scheme'] ?? null);
        $port = self::resolveClientPort((int) ($options['port'] ?? 0), $scheme);

        return [
            'enabled' => true,
            'key' => $key,
            'host' => self::resolveClientHost($options['host'] ?? null),
            'port' => $port,
            'scheme' => $scheme,
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

    protected static function resolveClientScheme(mixed $scheme): string
    {
        $scheme = ($scheme ?? 'https') === 'http' ? 'http' : 'https';

        if ($scheme === 'http') {
            $appScheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);

            if ($appScheme === 'https') {
                return 'https';
            }
        }

        return $scheme;
    }

    protected static function resolveClientPort(int $port, string $scheme): int
    {
        $clientPort = env('REVERB_CLIENT_PORT');

        if ($clientPort !== null && $clientPort !== '') {
            return (int) $clientPort;
        }

        $serverPort = (int) config('reverb.servers.reverb.port', 8080);
        $appPort = parse_url((string) config('app.url'), PHP_URL_PORT);
        $appPort = is_numeric($appPort) ? (int) $appPort : ($scheme === 'https' ? 443 : 80);

        // REVERB_PORT is often mistakenly set to the daemon port (8080/8081). Browsers reach Reverb via nginx on 443.
        if ($scheme === 'https' && ($port === $serverPort || in_array($port, [8080, 8081], true))) {
            return 443;
        }

        if ($port > 0) {
            return $port;
        }

        return $appPort > 0 ? $appPort : ($scheme === 'https' ? 443 : 80);
    }
}
