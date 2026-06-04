<?php

namespace Tests\Feature\Api\V1;

use App\Models\LiveShow;
use App\Models\LiveShowRegistration;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarRoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_message_returns_422_when_registration_missing(): void
    {
        $team = Team::query()->create([
            'owner_user_id' => User::factory()->create()->id,
            'name' => 'Webinar Team',
            'slug' => 'webinar-team',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
        ]);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Room video',
            'source' => 'uploaded',
            'status' => 'ready',
            'visibility' => 'public',
        ]);

        $liveShow = LiveShow::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'title' => 'Test Webinar',
            'status' => 'live',
            'starts_at' => now()->subMinute(),
            'settings' => ['chat_enabled' => true],
        ]);

        $this->postJson("/api/v1/player/webinars/{$liveShow->id}/messages", [
            'registration_id' => 999,
            'message' => 'Hello',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Registration not found. Open the registration page and join again before sending messages.');
    }

    public function test_player_show_parses_youtube_video_url(): void
    {
        $team = Team::query()->create([
            'owner_user_id' => User::factory()->create()->id,
            'name' => 'Video Team',
            'slug' => 'video-team',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
        ]);

        $liveShow = LiveShow::query()->create([
            'team_id' => $team->id,
            'title' => 'YouTube Webinar',
            'status' => 'live',
            'starts_at' => now()->subMinute(),
            'settings' => [
                'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'video_duration_seconds' => 120,
            ],
        ]);

        $this->getJson("/api/v1/player/webinars/{$liveShow->id}")
            ->assertOk()
            ->assertJsonPath('data.video_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->assertJsonPath('data.video_playback.provider', 'youtube')
            ->assertJsonPath(
                'data.video_playback.embed_url',
                'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            )
            ->assertJsonPath(
                'data.thumbnail_url',
                'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            );
    }

    public function test_player_show_includes_featured_products_with_schedule(): void
    {
        $team = Team::query()->create([
            'owner_user_id' => User::factory()->create()->id,
            'name' => 'Offers Team',
            'slug' => 'offers-team',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
        ]);

        $product = Product::query()->create([
            'team_id' => $team->id,
            'title' => 'Offer Product',
            'slug' => 'offer-product',
            'price' => 10,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $liveShow = LiveShow::query()->create([
            'team_id' => $team->id,
            'title' => 'Offers Webinar',
            'status' => 'live',
            'starts_at' => now()->subMinute(),
            'settings' => ['video_duration_seconds' => 60],
        ]);

        $liveShow->featuredProducts()->sync([
            $product->id => [
                'starts_at_ms' => 5000,
                'ends_at_ms' => null,
                'pin_order' => 0,
                'appearance' => 'popup',
                'cta_url' => null,
            ],
        ]);

        $this->getJson("/api/v1/player/webinars/{$liveShow->id}")
            ->assertOk()
            ->assertJsonPath('data.featured_products.0.id', $product->id)
            ->assertJsonPath('data.featured_products.0.starts_at_ms', 5000)
            ->assertJsonPath('data.featured_products.0.appearance', 'popup');
    }

    public function test_room_redirects_when_registration_query_is_invalid(): void
    {
        $liveShow = LiveShow::query()->create([
            'team_id' => Team::query()->create([
                'owner_user_id' => User::factory()->create()->id,
                'name' => 'Redirect Team',
                'slug' => 'redirect-team',
                'checkout_mode' => 'native',
                'external_provider' => 'none',
            ])->id,
            'title' => 'Redirect Webinar',
            'status' => 'live',
            'starts_at' => now()->subMinute(),
        ]);

        $this->get("/webinars/{$liveShow->id}/room?registration=999")
            ->assertRedirect(route('webinars.register', $liveShow));
    }

    public function test_send_message_succeeds_with_valid_registration(): void
    {
        $team = Team::query()->create([
            'owner_user_id' => User::factory()->create()->id,
            'name' => 'Chat Team',
            'slug' => 'chat-team',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
        ]);

        $liveShow = LiveShow::query()->create([
            'team_id' => $team->id,
            'title' => 'Chat Webinar',
            'status' => 'live',
            'starts_at' => now()->subMinute(),
            'settings' => ['chat_enabled' => true],
        ]);

        $registration = LiveShowRegistration::query()->create([
            'live_show_id' => $liveShow->id,
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'registered_at' => now(),
            'last_joined_at' => now(),
            'join_count' => 1,
        ]);

        $this->postJson("/api/v1/player/webinars/{$liveShow->id}/messages", [
            'registration_id' => $registration->id,
            'message' => 'Hello host',
        ])
            ->assertCreated()
            ->assertJsonPath('data.0.message', 'Hello host');
    }
}
