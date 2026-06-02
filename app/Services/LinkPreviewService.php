<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LinkPreviewService
{
    /**
     * @return array{url: string, title: ?string, description: ?string, image: ?string, site_name: ?string}|null
     */
    public function resolve(string $url): ?array
    {
        if (! $this->isAllowedUrl($url)) {
            return null;
        }

        $cacheKey = 'link_preview:'.hash('sha256', $url);

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($url) {
            return $this->fetch($url);
        });
    }

    protected function isAllowedUrl(string $url): bool
    {
        try {
            $parsed = parse_url($url);

            if (! is_array($parsed) || ! in_array(strtolower((string) ($parsed['scheme'] ?? '')), ['http', 'https'], true)) {
                return false;
            }

            $host = strtolower((string) ($parsed['host'] ?? ''));

            if ($host === '' || $host === 'localhost' || Str::endsWith($host, '.local')) {
                return false;
            }

            $ip = gethostbyname($host);

            if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{url: string, title: ?string, description: ?string, image: ?string, site_name: ?string}|null
     */
    protected function fetch(string $url): ?array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => 'SupremeWebLinkPreview/1.0'])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = (string) $response->body();

            if ($html === '') {
                return null;
            }

            return [
                'url' => $url,
                'title' => $this->metaContent($html, ['og:title', 'twitter:title', 'title']),
                'description' => $this->metaContent($html, ['og:description', 'twitter:description', 'description']),
                'image' => $this->metaContent($html, ['og:image', 'twitter:image']),
                'site_name' => $this->metaContent($html, ['og:site_name']),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $properties
     */
    protected function metaContent(string $html, array $properties): ?string
    {
        foreach ($properties as $property) {
            if ($property === 'title' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
                $value = trim(html_entity_decode(strip_tags($matches[1])));

                if ($value !== '') {
                    return Str::limit($value, 200);
                }
            }

            $pattern = '/<meta[^>]+(?:property|name)=["\']'.preg_quote($property, '/').'["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i';
            if (preg_match($pattern, $html, $matches)) {
                $value = trim(html_entity_decode($matches[1]));

                if ($value !== '') {
                    return Str::limit($value, 500);
                }
            }

            $patternReverse = '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']'.preg_quote($property, '/').'["\'][^>]*>/i';
            if (preg_match($patternReverse, $html, $matches)) {
                $value = trim(html_entity_decode($matches[1]));

                if ($value !== '') {
                    return Str::limit($value, 500);
                }
            }
        }

        return null;
    }
}
