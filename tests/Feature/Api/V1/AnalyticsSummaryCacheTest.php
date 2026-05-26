<?php

namespace Tests\Feature\Api\V1;

use App\Models\AnalyticsRollup;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsSummaryCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_summary_response_is_cached_briefly(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);
        Cache::flush();
        $metricDate = now()->toDateString();

        $rollup = AnalyticsRollup::query()->create([
            'team_id' => $team->id,
            'metric_date' => $metricDate,
            'metric_name' => 'video_view',
            'value_unsigned' => 10,
            'value_decimal' => 0,
        ]);

        $first = $this->getJson("/api/v1/analytics/summary?team_id={$team->id}&from={$metricDate}&to={$metricDate}");
        $first->assertOk();
        $firstMetrics = $first->json('metrics') ?? [];

        $rollup->update([
            'value_unsigned' => 99,
        ]);

        $second = $this->getJson("/api/v1/analytics/summary?team_id={$team->id}&from={$metricDate}&to={$metricDate}");
        $second->assertOk();
        $secondMetrics = $second->json('metrics') ?? [];

        $this->assertSame($firstMetrics, $secondMetrics);
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
