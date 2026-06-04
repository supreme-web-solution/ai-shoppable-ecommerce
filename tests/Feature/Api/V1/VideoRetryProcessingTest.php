<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ProcessVideoAssetJob;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use App\Services\Media\LocalVideoStagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoRetryProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_processing_redispatches_job_when_staging_file_exists(): void
    {
        Queue::fake();
        Storage::fake('local');

        $relativePath = LocalVideoStagingService::STAGING_DIR.'/retry-test.mp4';
        Storage::disk('local')->put($relativePath, 'fake-video-bytes');

        $owner = User::factory()->create();
        $team = Team::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Retry Team',
            'slug' => 'retry-team',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
        ]);
        $owner->update(['team_id' => $team->id]);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Stuck video',
            'source' => 'uploaded',
            'status' => 'processing',
            'visibility' => 'private',
            'metadata' => [
                'local_staging' => [
                    'relative_path' => $relativePath,
                    'stored_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/admin/videos/{$video->id}/retry-processing")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing');

        Queue::assertPushed(ProcessVideoAssetJob::class, function (ProcessVideoAssetJob $job) use ($video): bool {
            return $job->videoId === $video->id && $job->localFilePath !== null;
        });
    }

    public function test_retry_processing_requires_staging_file(): void
    {
        $owner = User::factory()->create();
        $team = Team::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Missing Staging Team',
            'slug' => 'missing-staging-team',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
        ]);
        $owner->update(['team_id' => $team->id]);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'No staging',
            'source' => 'uploaded',
            'status' => 'processing',
            'visibility' => 'private',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/admin/videos/{$video->id}/retry-processing")
            ->assertStatus(422);
    }
}
