<?php

namespace Tests\Feature\Api\V1;

use App\Models\LiveShow;
use App\Models\LiveShowRegistration;
use App\Models\LiveShowViewSession;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarViewTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_view_counts_once_per_guest_viewer_key(): void
    {
        $liveShow = $this->createLiveShow();

        $this->getJson("/api/v1/player/webinars/{$liveShow->id}?viewer_key=abc12345")
            ->assertOk()
            ->assertJsonPath('data.views_count', 1);

        $this->getJson("/api/v1/player/webinars/{$liveShow->id}?viewer_key=abc12345")
            ->assertOk()
            ->assertJsonPath('data.views_count', 1);

        $this->assertDatabaseCount('live_show_view_sessions', 1);
    }

    public function test_room_view_counts_once_per_registration(): void
    {
        $liveShow = $this->createLiveShow();
        $registration = LiveShowRegistration::query()->create([
            'live_show_id' => $liveShow->id,
            'full_name' => 'Jane Viewer',
            'email' => 'jane@example.com',
            'registered_at' => now(),
            'last_joined_at' => now(),
            'join_count' => 1,
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->getJson(
                "/api/v1/player/webinars/{$liveShow->id}?registration_id={$registration->id}",
            )
                ->assertOk()
                ->assertJsonPath('data.views_count', 1);
        }

        $this->assertDatabaseHas('live_show_view_sessions', [
            'live_show_id' => $liveShow->id,
            'viewer_key' => "reg:{$registration->id}",
        ]);
    }

    public function test_offer_polling_does_not_increment_views(): void
    {
        $liveShow = $this->createLiveShow();

        $this->getJson("/api/v1/player/webinars/{$liveShow->id}?viewer_key=viewer-one&track_view=1")
            ->assertOk()
            ->assertJsonPath('data.views_count', 1);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->getJson("/api/v1/player/webinars/{$liveShow->id}?viewer_key=viewer-one&track_view=0")
                ->assertOk()
                ->assertJsonPath('data.views_count', 1);
        }
    }

    public function test_different_viewer_keys_increment_unique_view_count(): void
    {
        $liveShow = $this->createLiveShow();

        $this->getJson("/api/v1/player/webinars/{$liveShow->id}?viewer_key=viewer-one")
            ->assertJsonPath('data.views_count', 1);

        $this->getJson("/api/v1/player/webinars/{$liveShow->id}?viewer_key=viewer-two")
            ->assertJsonPath('data.views_count', 2);

        $this->assertSame(2, LiveShowViewSession::query()->where('live_show_id', $liveShow->id)->count());
    }

    public function test_registration_page_load_does_not_increment_views(): void
    {
        $liveShow = $this->createLiveShow(['views_count' => 0]);

        $this->getJson("/api/v1/player/webinars/{$liveShow->id}?track_view=0")
            ->assertOk()
            ->assertJsonPath('data.views_count', 0);

        $this->assertDatabaseCount('live_show_view_sessions', 0);
    }

    protected function createLiveShow(array $settings = []): LiveShow
    {
        $team = Team::query()->create([
            'owner_user_id' => User::factory()->create()->id,
            'name' => 'View Team',
            'slug' => 'view-team-'.fake()->unique()->slug(),
            'checkout_mode' => 'native',
            'external_provider' => 'none',
        ]);

        return LiveShow::query()->create([
            'team_id' => $team->id,
            'title' => 'Tracked Webinar',
            'status' => 'live',
            'starts_at' => now()->subMinute(),
            'settings' => array_merge(['views_count' => 0], $settings),
        ]);
    }
}
