<?php

namespace App\Support;

class ReverbAllowedOrigins
{
    /**
     * @return list<string>
     */
    public static function resolve(): array
    {
        $origins = array_filter(array_map(
            static fn (string $origin): string => trim($origin),
            explode(',', (string) env('REVERB_ALLOWED_ORIGINS', '*')),
        ));

        if (in_array('*', $origins, true)) {
            return ['*'];
        }

        foreach (self::appOrigins() as $origin) {
            if (! in_array($origin, $origins, true)) {
                $origins[] = $origin;
            }
        }

        return array_values($origins);
    }

    /**
     * @return list<string>
     */
    protected static function appOrigins(): array
    {
        $appUrl = rtrim((string) env('APP_URL', ''), '/');

        if ($appUrl === '') {
            return [];
        }

        $origins = [$appUrl];

        if (str_starts_with($appUrl, 'https://')) {
            $origins[] = 'http://'.substr($appUrl, 8);
        } elseif (str_starts_with($appUrl, 'http://')) {
            $origins[] = 'https://'.substr($appUrl, 7);
        }

        return array_values(array_unique($origins));
    }
}
