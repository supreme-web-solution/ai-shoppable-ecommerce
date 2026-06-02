<?php

namespace Tests\Feature\Api\V1;

use App\Models\AnalyticsEvent;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsSummaryFromEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_reads_raw_events_when_rollups_are_empty(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Demo Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $metricDate = now()->toDateString();

        foreach (range(1, 3) as $i) {
            AnalyticsEvent::query()->create([
                'team_id' => $team->id,
                'video_id' => $video->id,
                'event_name' => 'video_view',
                'source' => 'embed_player',
                'platform' => 'web_embed',
                'session_key' => "session-{$i}",
                'occurred_at' => now(),
            ]);
        }

        AnalyticsEvent::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'event_name' => 'add_to_cart',
            'source' => 'embed_player',
            'platform' => 'web_embed',
            'session_key' => 'session-cart',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson(
            "/api/v1/analytics/summary?team_id={$team->id}&from={$metricDate}&to={$metricDate}",
        );

        $response->assertOk();
        $response->assertJsonPath('data_source', 'events');
        $response->assertJsonPath('metrics.video_view.count', 3);
        $response->assertJsonPath('metrics.add_to_cart.count', 1);
        $response->assertJsonPath('totals.events', 4);
        $response->assertJsonPath('top_videos.0.title', 'Demo Video');
        $response->assertJsonPath('top_videos.0.total', 4);
        $response->assertJsonCount(1, 'daily_series');
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
