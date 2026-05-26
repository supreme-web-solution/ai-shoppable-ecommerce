<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\TransitionLiveShowsJob;
use App\Models\Embed;
use App\Models\LiveShow;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveShowLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_transition_live_shows_job_updates_statuses_and_video_publish_state(): void
    {
        [$team] = $this->createTeamWithOwner();

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Scheduled Premiere Video',
            'source' => 'uploaded',
            'status' => 'ready',
            'visibility' => 'public',
        ]);

        $scheduled = LiveShow::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'title' => 'Tonight Show',
            'status' => 'scheduled',
            'starts_at' => now()->subMinute(),
            'is_premiere' => true,
        ]);

        $liveToEnd = LiveShow::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'title' => 'Already Live',
            'status' => 'live',
            'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->subMinute(),
            'is_premiere' => true,
        ]);

        (new TransitionLiveShowsJob())->handle();

        $this->assertSame('live', $scheduled->fresh()->status);
        $this->assertSame('ended', $liveToEnd->fresh()->status);
        $this->assertSame('published', $video->fresh()->status);
        $this->assertNotNull($video->fresh()->published_at);
    }

    public function test_player_live_show_endpoint_supports_embed_countdown_and_domain_rules(): void
    {
        [$team] = $this->createTeamWithOwner();

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Live Show Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        LiveShow::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'title' => 'Coming Soon',
            'status' => 'scheduled',
            'starts_at' => now()->addMinutes(5),
            'is_premiere' => true,
        ]);

        Embed::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'name' => 'Live Embed',
            'type' => 'vertical_feed',
            'slug' => 'live-embed',
            'signed_key' => hash('sha256', 'live-embed'),
            'is_active' => true,
            'allowed_domains' => ['allowed.test'],
        ]);

        $allowed = $this->getJson('/api/v1/player/live-show?embed_slug=live-embed&video_id='.$video->id, [
            'Origin' => 'https://allowed.test',
        ]);

        $allowed->assertOk()
            ->assertJsonPath('data.state', 'scheduled')
            ->assertJsonPath('data.video_id', $video->id);

        $this->assertIsInt($allowed->json('data.countdown_seconds'));

        $blocked = $this->getJson('/api/v1/player/live-show?embed_slug=live-embed&video_id='.$video->id, [
            'Origin' => 'https://blocked.test',
        ]);

        $blocked->assertForbidden();
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
