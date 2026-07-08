<?php

namespace App\Services\Social;

class SocialPostAdapter
{
    /**
     * @return array{content: string, platformSpecificData: array<string, mixed>}
     */
    public function adapt(
        string $platform,
        string $fullCaption,
        ?string $mediaType = null,
        ?string $title = null,
    ): array {
        $platform = $this->normalizePlatform($platform);
        $limits = config('social_publishing.platform_content_limits', []);

        return match ($platform) {
            'twitter' => [
                'content' => $this->truncateForTwitter(
                    $fullCaption,
                    (int) ($limits['twitter'] ?? 280),
                ),
                'platformSpecificData' => [],
            ],
            'tiktok' => $mediaType === 'video'
                ? [
                    'content' => $this->truncateText(
                        $fullCaption,
                        (int) ($limits['tiktok_video'] ?? 2200),
                    ),
                    'platformSpecificData' => [],
                ]
                : [
                    'content' => $this->truncateText(
                        $this->shortTitle($title, $fullCaption),
                        (int) ($limits['tiktok_photo'] ?? 90),
                    ),
                    'platformSpecificData' => [
                        'description' => $this->truncateText(
                            $fullCaption,
                            (int) ($limits['tiktok_photo_description'] ?? 4000),
                        ),
                    ],
                ],
            'youtube' => [
                'content' => $fullCaption,
                'platformSpecificData' => [
                    'title' => $this->truncateText(
                        $this->shortTitle($title, $fullCaption),
                        (int) ($limits['youtube_title'] ?? 100),
                    ),
                ],
            ],
            'instagram' => [
                'content' => $this->truncateText(
                    $fullCaption,
                    (int) ($limits['instagram'] ?? 2200),
                ),
                'platformSpecificData' => [],
            ],
            'facebook' => [
                'content' => $this->truncateText(
                    $fullCaption,
                    (int) ($limits['facebook'] ?? 63206),
                ),
                'platformSpecificData' => [],
            ],
            'linkedin' => [
                'content' => $this->truncateText(
                    $fullCaption,
                    (int) ($limits['linkedin'] ?? 3000),
                ),
                'platformSpecificData' => [],
            ],
            default => [
                'content' => $fullCaption,
                'platformSpecificData' => [],
            ],
        };
    }

    public function limitForPlatform(string $platform, ?string $mediaType = null): ?int
    {
        $platform = $this->normalizePlatform($platform);
        $limits = config('social_publishing.platform_content_limits', []);

        return match ($platform) {
            'twitter' => (int) ($limits['twitter'] ?? 280),
            'tiktok' => $mediaType === 'video'
                ? (int) ($limits['tiktok_video'] ?? 2200)
                : (int) ($limits['tiktok_photo'] ?? 90),
            'youtube' => null,
            'instagram' => (int) ($limits['instagram'] ?? 2200),
            'facebook' => (int) ($limits['facebook'] ?? 63206),
            'linkedin' => (int) ($limits['linkedin'] ?? 3000),
            default => null,
        };
    }

    public function effectiveLength(string $platform, string $text, ?string $mediaType = null): int
    {
        $platform = $this->normalizePlatform($platform);

        if ($platform === 'twitter') {
            return $this->twitterEffectiveLength($text);
        }

        return mb_strlen($text);
    }

    public function truncateText(string $text, int $maxLength): string
    {
        $text = trim($text);

        if ($maxLength <= 0 || mb_strlen($text) <= $maxLength) {
            return $text;
        }

        if ($maxLength === 1) {
            return '…';
        }

        $truncated = mb_substr($text, 0, $maxLength - 1);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > (int) ($maxLength * 0.6)) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated).'…';
    }

    public function truncateForTwitter(string $text, int $maxLength): string
    {
        $text = trim($text);

        if ($this->twitterEffectiveLength($text) <= $maxLength) {
            return $text;
        }

        $low = 0;
        $high = mb_strlen($text);
        $best = '';

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $candidate = mb_substr($text, 0, $mid);

            if ($this->twitterEffectiveLength($candidate) <= $maxLength) {
                $best = $candidate;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        $best = rtrim($best);

        if ($best === '') {
            return $this->truncateText($text, $maxLength);
        }

        if ($this->twitterEffectiveLength($best.'…') <= $maxLength) {
            return $best.'…';
        }

        return $this->truncateText($best, $maxLength);
    }

    public function twitterEffectiveLength(string $text): int
    {
        $urlLength = (int) config('social_publishing.twitter_url_length', 23);
        $length = 0;
        $offset = 0;

        if (preg_match_all('/https?:\/\/[^\s]+/iu', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$url, $position]) {
                $length += mb_strlen(mb_substr($text, $offset, $position - $offset));
                $length += $urlLength;
                $offset = $position + mb_strlen($url);
            }
        }

        $length += mb_strlen(mb_substr($text, $offset));

        return $length;
    }

    public function shortTitle(?string $title, string $fullCaption): string
    {
        $title = trim((string) $title);

        if ($title !== '') {
            return $title;
        }

        $firstLine = trim((string) (preg_split('/\R/u', $fullCaption, 2)[0] ?? ''));

        return $firstLine !== '' ? $firstLine : $fullCaption;
    }

    protected function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return $platform === 'x' ? 'twitter' : $platform;
    }
}
