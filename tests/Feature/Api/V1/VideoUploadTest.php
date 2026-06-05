<?php

namespace Tests\Feature\Api\V1;

use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use App\Services\CloudinaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class VideoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_request_signed_cloudinary_upload_params(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $cloudinary = Mockery::mock(CloudinaryService::class);
        $cloudinary->shouldReceive('signedVideoUploadParams')
            ->once()
            ->andReturn([
                'direct_upload' => true,
                'cloud_name' => 'demo',
                'api_key' => 'key',
                'timestamp' => 1_700_000_000,
                'signature' => 'signed',
                'folder' => 'ai-video-commerce',
                'public_id' => 'video_test',
                'upload_url' => 'https://api.cloudinary.com/v1_1/demo/video/upload',
            ]);
        $this->app->instance(CloudinaryService::class, $cloudinary);

        $response = $this->postJson('/api/v1/admin/videos/upload-params', [
            'team_id' => $team->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('direct_upload', true)
            ->assertJsonPath('upload_url', 'https://api.cloudinary.com/v1_1/demo/video/upload');
    }

    public function test_admin_can_create_video_from_direct_cloudinary_upload(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/admin/videos', [
            'team_id' => $team->id,
            'title' => 'Uploaded webinar',
            'source' => 'uploaded',
            'cloudinary_public_id' => 'ai-video-commerce/video_test',
            'playback_url' => 'https://res.cloudinary.com/demo/video/upload/v1/video_test.mp4',
            'thumbnail_url' => 'https://res.cloudinary.com/demo/video/upload/so_0/video_test.jpg',
            'duration_seconds' => 42,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.cloudinary_public_id', 'ai-video-commerce/video_test');

        $this->assertDatabaseHas('videos', [
            'team_id' => $team->id,
            'title' => 'Uploaded webinar',
            'status' => 'ready',
            'cloudinary_public_id' => 'ai-video-commerce/video_test',
        ]);
    }

    public function test_upload_params_falls_back_when_cloudinary_is_unavailable(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $cloudinary = Mockery::mock(CloudinaryService::class);
        $cloudinary->shouldReceive('signedVideoUploadParams')
            ->once()
            ->andThrow(new \RuntimeException('Cloudinary is not configured.'));
        $this->app->instance(CloudinaryService::class, $cloudinary);

        $response = $this->postJson('/api/v1/admin/videos/upload-params', [
            'team_id' => $team->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('direct_upload', false);
    }

    /**
     * @return array{0: Team, 1: User}
     */
    protected function createTeamWithOwner(): array
    {
        $owner = User::factory()->create();

        $team = Team::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Team '.fake()->unique()->word(),
            'slug' => 'team-'.fake()->unique()->slug(),
            'checkout_mode' => 'hybrid',
            'external_provider' => 'none',
        ]);

        $team->users()->attach($owner->id, ['role' => 'owner']);
        $owner->update(['team_id' => $team->id]);

        return [$team, $owner];
    }
}
