<?php

namespace Tests\Unit\Social;

use App\Services\Social\SocialPostAdapter;
use Tests\TestCase;

class SocialPostAdapterTest extends TestCase
{
    protected SocialPostAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = app(SocialPostAdapter::class);
    }

    public function test_twitter_truncation_counts_urls_as_23_characters(): void
    {
        $text = 'Check this out https://example.com/very/long/path and more text that pushes over the limit for twitter posting';

        $adapted = $this->adapter->adapt('twitter', $text);

        $this->assertLessThanOrEqual(280, $this->adapter->twitterEffectiveLength($adapted['content']));
        $this->assertStringContainsString('https://example.com/very/long/path', $text);
    }

    public function test_tiktok_video_caption_is_truncated_to_limit(): void
    {
        $text = str_repeat('A', 3000);

        $adapted = $this->adapter->adapt('tiktok', $text, 'video');

        $this->assertLessThanOrEqual(2200, mb_strlen($adapted['content']));
        $this->assertSame([], $adapted['platformSpecificData']);
    }

    public function test_youtube_uses_short_title_in_platform_specific_data(): void
    {
        $adapted = $this->adapter->adapt(
            'youtube',
            "Full description line one\nMore description here",
            'video',
            'My Launch Video',
        );

        $this->assertSame("Full description line one\nMore description here", $adapted['content']);
        $this->assertSame('My Launch Video', $adapted['platformSpecificData']['title']);
    }

    public function test_youtube_title_is_truncated_to_100_characters(): void
    {
        $adapted = $this->adapter->adapt(
            'youtube',
            'Description',
            'video',
            str_repeat('Title ', 30),
        );

        $this->assertLessThanOrEqual(100, mb_strlen($adapted['platformSpecificData']['title']));
    }

    public function test_instagram_and_facebook_keep_full_caption_within_limits(): void
    {
        $caption = 'Shop now: https://example.com/shop/demo';

        $instagram = $this->adapter->adapt('instagram', $caption, 'video');
        $facebook = $this->adapter->adapt('facebook', $caption, 'video');

        $this->assertSame($caption, $instagram['content']);
        $this->assertSame($caption, $facebook['content']);
    }
}
