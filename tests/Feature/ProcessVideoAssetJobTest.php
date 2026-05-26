<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideoAssetJob;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use App\Services\CloudinaryService;
use App\Services\Media\LocalVideoStagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProcessVideoAssetJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_deletes_local_staging_file_after_cloudinary_upload(): void
    {
        Storage::fake('local');

        $relativePath = LocalVideoStagingService::STAGING_DIR.'/test-video.mp4';
        Storage::disk('local')->put($relativePath, 'fake-video-bytes');
        $absolutePath = Storage::disk('local')->path($relativePath);

        $video = $this->createVideo();

        app(LocalVideoStagingService::class)->rememberForVideo($video, $absolutePath);

        $cloudinary = Mockery::mock(CloudinaryService::class);
        $cloudinary->shouldReceive('uploadVideo')
            ->once()
            ->with($absolutePath, Mockery::type('array'))
            ->andReturn([
                'public_id' => 'video_'.$video->id,
                'secure_url' => 'https://res.cloudinary.com/demo/video/upload/v1/video_'.$video->id.'.mp4',
                'thumbnail_url' => 'https://res.cloudinary.com/demo/video/upload/so_0/video_'.$video->id.'.jpg',
                'duration' => 12,
                'used_mock' => false,
            ]);

        $this->app->instance(CloudinaryService::class, $cloudinary);

        $job = new ProcessVideoAssetJob($video->id, $absolutePath);
        $job->handle($cloudinary, app(LocalVideoStagingService::class));

        Storage::disk('local')->assertMissing($relativePath);
        $this->assertNull(data_get($video->fresh()->metadata, 'local_staging'));
        $this->assertSame('ready', $video->fresh()->status);
    }

    protected function createVideo(): Video
    {
        $owner = User::factory()->create();
        $team = Team::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Media Team',
            'slug' => 'media-team',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
        ]);

        return Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Staging cleanup test',
            'source' => 'uploaded',
            'status' => 'processing',
            'visibility' => 'private',
        ]);
    }
}
