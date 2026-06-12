<?php

namespace Tests\Feature\Api\V1;

use App\Models\LiveShow;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LiveShowSimulcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_show_update_persists_daily_simulcast_destinations(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $liveShow = LiveShow::query()->create([
            'team_id' => $team->id,
            'title' => 'Simulcast Show',
            'status' => 'scheduled',
            'starts_at' => now()->addHour(),
            'settings' => [
                'source_type' => 'daily',
                'daily' => [
                    'room_name' => 'ls-1-1-test',
                    'room_url' => 'https://example.daily.co/ls-1-1-test',
                ],
            ],
        ]);

        $this->patchJson("/api/v1/admin/live-shows/{$liveShow->id}", [
            'settings' => [
                'source_type' => 'daily',
                'daily' => [
                    'room_name' => 'ls-1-1-test',
                    'room_url' => 'https://example.daily.co/ls-1-1-test',
                    'streaming_endpoints' => [
                        [
                            'name' => 'YouTube Live',
                            'endpoint' => 'rtmps://a.rtmp.youtube.com/live2/example-key',
                        ],
                        [
                            'name' => 'Facebook Live',
                            'endpoint' => 'rtmps://live-api-s.facebook.com:443/rtmp/example-key',
                        ],
                    ],
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.daily.streaming_endpoints.0.name', 'YouTube Live')
            ->assertJsonPath('data.daily.streaming_endpoints.0.endpoint', 'rtmps://a.rtmp.youtube.com/live2/example-key')
            ->assertJsonPath('data.daily.streaming_endpoints.1.name', 'Facebook Live');

        $liveShow->refresh();

        $this->assertCount(2, data_get($liveShow->settings, 'daily.streaming_endpoints', []));
    }

    public function test_live_show_update_rejects_non_rtmp_simulcast_urls(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $liveShow = LiveShow::query()->create([
            'team_id' => $team->id,
            'title' => 'Simulcast Show',
            'status' => 'scheduled',
            'starts_at' => now()->addHour(),
            'settings' => ['source_type' => 'daily'],
        ]);

        $this->patchJson("/api/v1/admin/live-shows/{$liveShow->id}", [
            'settings' => [
                'daily' => [
                    'streaming_endpoints' => [
                        [
                            'name' => 'Invalid',
                            'endpoint' => 'https://example.com/not-rtmp',
                        ],
                    ],
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.daily.streaming_endpoints.0.endpoint']);
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
