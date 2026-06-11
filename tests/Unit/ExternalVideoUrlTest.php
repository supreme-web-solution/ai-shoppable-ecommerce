<?php

namespace Tests\Unit;

use App\Support\ExternalVideoUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExternalVideoUrlTest extends TestCase
{
    #[DataProvider('youtubeUrlsProvider')]
    public function test_parses_youtube_urls(string $url, string $expectedId): void
    {
        $parsed = ExternalVideoUrl::parse($url);

        $this->assertNotNull($parsed);
        $this->assertSame(ExternalVideoUrl::PROVIDER_YOUTUBE, $parsed['provider']);
        $this->assertStringContainsString($expectedId, (string) $parsed['embed_url']);
        $this->assertNull($parsed['direct_url']);
    }

    public static function youtubeUrlsProvider(): array
    {
        return [
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
        ];
    }

    public function test_parses_vimeo_url(): void
    {
        $parsed = ExternalVideoUrl::parse('https://vimeo.com/123456789');

        $this->assertNotNull($parsed);
        $this->assertSame(ExternalVideoUrl::PROVIDER_VIMEO, $parsed['provider']);
        $this->assertSame('https://player.vimeo.com/video/123456789', $parsed['embed_url']);
    }

    public function test_parses_direct_mp4_url(): void
    {
        $url = 'https://res.cloudinary.com/demo/video/upload/v1/sample.mp4';
        $parsed = ExternalVideoUrl::parse($url);

        $this->assertNotNull($parsed);
        $this->assertSame(ExternalVideoUrl::PROVIDER_DIRECT, $parsed['provider']);
        $this->assertSame($url, $parsed['direct_url']);
        $this->assertNull($parsed['embed_url']);
    }

    public function test_parses_restream_url(): void
    {
        $url = 'https://app.restream.io/player/example-channel';
        $parsed = ExternalVideoUrl::parse($url);

        $this->assertNotNull($parsed);
        $this->assertSame(ExternalVideoUrl::PROVIDER_RESTREAM, $parsed['provider']);
        $this->assertSame($url, $parsed['embed_url']);
        $this->assertNull($parsed['direct_url']);
    }
}
