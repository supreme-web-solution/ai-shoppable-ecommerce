<?php

namespace Tests\Feature\Api\V1;

use App\Models\LiveShow;
use App\Models\LiveShowRegistration;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarWatchProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_watch_progress_marks_half_and_end_using_configured_duration(): void
    {
        $liveShow = $this->createLiveShow(['video_duration_seconds' => 100]);

        $registration = LiveShowRegistration::query()->create([
            'live_show_id' => $liveShow->id,
            'full_name' => 'Viewer One',
            'email' => 'viewer@example.com',
            'registered_at' => now(),
        ]);

        $this->postJson("/api/v1/player/webinars/{$liveShow->id}/watch-progress", [
            'registration_id' => $registration->id,
            'position_ms' => 50_000,
        ])->assertOk()
            ->assertJsonPath('data.reached_half_at', fn ($value) => $value !== null)
            ->assertJsonPath('data.watched_to_end_at', null);

        $registration->refresh();
        $this->assertSame(50_000, $registration->max_watch_ms);
        $this->assertNotNull($registration->reached_half_at);
        $this->assertNull($registration->watched_to_end_at);

        $this->postJson("/api/v1/player/webinars/{$liveShow->id}/watch-progress", [
            'registration_id' => $registration->id,
            'position_ms' => 100_000,
            'completed' => true,
        ])->assertOk()
            ->assertJsonPath('data.watched_to_end_at', fn ($value) => $value !== null);

        $registration->refresh();
        $this->assertNotNull($registration->watched_to_end_at);
    }

    public function test_admin_live_show_includes_watch_counts(): void
    {
        $liveShow = $this->createLiveShow(['video_duration_seconds' => 60]);
        $owner = User::factory()->create();
        $owner->update(['team_id' => $liveShow->team_id]);
        $liveShow->team->users()->attach($owner->id, ['role' => 'owner']);

        LiveShowRegistration::query()->create([
            'live_show_id' => $liveShow->id,
            'full_name' => 'Half Viewer',
            'email' => 'half@example.com',
            'registered_at' => now(),
            'reached_half_at' => now(),
        ]);

        LiveShowRegistration::query()->create([
            'live_show_id' => $liveShow->id,
            'full_name' => 'End Viewer',
            'email' => 'end@example.com',
            'registered_at' => now(),
            'reached_half_at' => now(),
            'watched_to_end_at' => now(),
        ]);

        $this->actingAs($owner)
            ->getJson("/api/v1/admin/live-shows/{$liveShow->id}")
            ->assertOk()
            ->assertJsonPath('data.watched_half_count', 2)
            ->assertJsonPath('data.watched_end_count', 1);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function createLiveShow(array $settings = []): LiveShow
    {
        $owner = User::factory()->create();

        $team = Team::query()->create([
            'name' => 'Watch Team',
            'slug' => 'watch-team',
            'owner_user_id' => $owner->id,
            'checkout_mode' => 'native',
            'external_provider' => 'none',
        ]);

        return LiveShow::query()->create([
            'team_id' => $team->id,
            'title' => 'Watch Webinar',
            'status' => 'live',
            'starts_at' => now()->subMinute(),
            'settings' => $settings,
        ]);
    }
}
