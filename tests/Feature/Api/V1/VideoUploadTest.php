<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ProcessVideoAssetJob;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use App\Services\CloudinaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class VideoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_video_awaiting_background_upload(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/admin/videos', [
            'team_id' => $team->id,
            'title' => 'Uploaded webinar',
            'source' => 'uploaded',
            'awaiting_upload' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.metadata.pending_upload.token', fn ($value) => is_string($value) && $value !== '');

        $this->assertDatabaseHas('videos', [
            'team_id' => $team->id,
            'title' => 'Uploaded webinar',
            'status' => 'processing',
        ]);
    }

    public function test_admin_can_upload_video_in_chunks_and_dispatch_processing_job(): void
    {
        Queue::fake();

        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Chunk upload test',
            'source' => 'uploaded',
            'status' => 'processing',
            'metadata' => [
                'pending_upload' => [
                    'token' => '550e8400-e29b-41d4-a716-446655440000',
                ],
            ],
        ]);

        $response = $this->postJson("/api/v1/admin/videos/{$video->id}/upload-chunk", [
            'team_id' => $team->id,
            'upload_token' => '550e8400-e29b-41d4-a716-446655440000',
            'chunk_index' => 0,
            'total_chunks' => 1,
            'original_name' => 'clip.mp4',
            'file' => UploadedFile::fake()->create('clip.mp4', 128, 'video/mp4'),
        ]);

        $response->assertOk()
            ->assertJsonPath('complete', true);

        $video->refresh();

        $this->assertNull(data_get($video->metadata, 'pending_upload'));
        $this->assertSame('processing', $video->status);
        $this->assertNotNull(data_get($video->metadata, 'local_staging.relative_path'));

        Queue::assertPushed(ProcessVideoAssetJob::class, function (ProcessVideoAssetJob $job) use ($video): bool {
            return $job->videoId === $video->id && is_string($job->localFilePath);
        });
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
