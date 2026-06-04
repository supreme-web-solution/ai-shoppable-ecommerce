<?php

namespace Tests\Unit;

use App\Models\Video;
use PHPUnit\Framework\TestCase;

class VideoDisplayTitleTest extends TestCase
{
    public function test_display_title_falls_back_when_title_is_opaque_hash(): void
    {
        $video = new Video([
            'title' => '624a4ec41e3840ff92effe5206b73593',
            'cloudinary_public_id' => 'team/videos/video_12',
            'metadata' => ['original_name' => 'Summer Promo.mp4'],
        ]);

        $this->assertSame('Summer Promo.mp4', $video->displayTitle());
    }

    public function test_display_title_uses_friendly_title_when_present(): void
    {
        $video = new Video([
            'title' => 'Product Launch Reel',
        ]);

        $this->assertSame('Product Launch Reel', $video->displayTitle());
    }

    public function test_display_title_uses_video_id_when_nothing_else_is_available(): void
    {
        $video = new Video([
            'title' => '624a4ec41e3840ff92effe5206b73593',
            'cloudinary_public_id' => '624a4ec41e3840ff92effe5206b73593',
        ]);
        $video->id = 7;

        $this->assertSame('Video #7', $video->displayTitle());
    }
}
