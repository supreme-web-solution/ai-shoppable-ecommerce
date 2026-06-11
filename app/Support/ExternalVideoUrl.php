<?php

namespace App\Support;

class ExternalVideoUrl
{
    public const PROVIDER_DIRECT = 'direct';

    public const PROVIDER_YOUTUBE = 'youtube';

    public const PROVIDER_VIMEO = 'vimeo';

    public const PROVIDER_RESTREAM = 'restream';

    /**
     * @return array{
     *     provider: string,
     *     source_url: string,
     *     embed_url: string|null,
     *     direct_url: string|null,
     *     thumbnail_url: string|null
     * }|null
     */
    public static function parse(?string $url): ?array
    {
        $url = trim((string) $url);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        if (self::restreamUrl($url)) {
            return [
                'provider' => self::PROVIDER_RESTREAM,
                'source_url' => $url,
                'embed_url' => $url,
                'direct_url' => null,
                'thumbnail_url' => null,
            ];
        }

        $youtubeId = self::youtubeId($url);

        if ($youtubeId !== null) {
            return [
                'provider' => self::PROVIDER_YOUTUBE,
                'source_url' => $url,
                'embed_url' => 'https://www.youtube-nocookie.com/embed/'.$youtubeId,
                'direct_url' => null,
                'thumbnail_url' => 'https://img.youtube.com/vi/'.$youtubeId.'/hqdefault.jpg',
            ];
        }

        $vimeoId = self::vimeoId($url);

        if ($vimeoId !== null) {
            return [
                'provider' => self::PROVIDER_VIMEO,
                'source_url' => $url,
                'embed_url' => 'https://player.vimeo.com/video/'.$vimeoId,
                'direct_url' => null,
                'thumbnail_url' => null,
            ];
        }

        return [
            'provider' => self::PROVIDER_DIRECT,
            'source_url' => $url,
            'embed_url' => null,
            'direct_url' => $url,
            'thumbnail_url' => null,
        ];
    }

    public static function youtubeId(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (str_contains($host, 'youtu.be')) {
            $id = trim($path, '/');

            return self::normalizeYoutubeId($id);
        }

        if (! str_contains($host, 'youtube.com') && ! str_contains($host, 'youtube-nocookie.com')) {
            return null;
        }

        if (preg_match('~^/embed/([^/?]+)~', $path, $matches) === 1) {
            return self::normalizeYoutubeId($matches[1]);
        }

        if (preg_match('~^/shorts/([^/?]+)~', $path, $matches) === 1) {
            return self::normalizeYoutubeId($matches[1]);
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        $id = (string) ($query['v'] ?? '');

        return self::normalizeYoutubeId($id);
    }

    public static function restreamUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return str_contains($host, 'restream.io');
    }

    public static function vimeoId(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (! str_contains($host, 'vimeo.com')) {
            return null;
        }

        if (preg_match('~/(?:video/)?(\d+)~', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected static function normalizeYoutubeId(string $id): ?string
    {
        $id = trim($id);

        if ($id === '' || ! preg_match('/^[\w-]{6,}$/', $id)) {
            return null;
        }

        return $id;
    }
}
