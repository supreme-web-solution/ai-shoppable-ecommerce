<?php

namespace Tests\Feature\Api\V1;

use App\Models\Embed;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_embed_feed_respects_allowed_domains(): void
    {
        [$team] = $this->createTeamWithOwner();
        Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Demo Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        Embed::query()->create([
            'team_id' => $team->id,
            'name' => 'Feed Embed',
            'type' => 'vertical_feed',
            'slug' => 'demo-feed',
            'signed_key' => hash('sha256', 'demo-feed'),
            'is_active' => true,
            'allowed_domains' => ['allowed.test'],
        ]);

        $allowed = $this->getJson('/api/v1/player/feed?embed_slug=demo-feed', [
            'Origin' => 'https://allowed.test',
        ]);
        $allowed->assertOk();

        $blocked = $this->getJson('/api/v1/player/feed?embed_slug=demo-feed', [
            'Origin' => 'https://blocked.test',
        ]);
        $blocked->assertForbidden();
    }

    public function test_player_cart_and_analytics_accept_embed_scoped_calls(): void
    {
        [$team] = $this->createTeamWithOwner();

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Video With Product',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $product = Product::query()->create([
            'team_id' => $team->id,
            'title' => 'Demo Product',
            'slug' => 'demo-product',
            'source' => 'native',
            'currency' => 'USD',
            'price' => 99.99,
        ]);

        Embed::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'name' => 'Player Embed',
            'type' => 'vertical_feed',
            'slug' => 'player-embed',
            'signed_key' => hash('sha256', 'player-embed'),
            'is_active' => true,
            'allowed_domains' => ['allowed.test'],
        ]);

        $withoutEmbedScope = $this->postJson('/api/v1/player/cart/items', [
            'team_id' => $team->id,
            'session_key' => 'embed-session',
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        $withoutEmbedScope->assertForbidden();

        $cartAdd = $this->postJson(
            '/api/v1/player/cart/items',
            [
                'team_id' => $team->id,
                'session_key' => 'embed-session',
                'product_id' => $product->id,
                'quantity' => 1,
            ],
            [
                'X-Embed-Slug' => 'player-embed',
                'Origin' => 'https://allowed.test',
            ],
        );
        $cartAdd->assertOk()->assertJsonPath('data.items.0.product_id', $product->id);

        $eventIngest = $this->postJson(
            '/api/v1/analytics/events',
            [
                'team_id' => $team->id,
                'video_id' => $video->id,
                'event_name' => 'video_view',
                'source' => 'embed_player',
                'platform' => 'web_embed',
                'session_key' => 'embed-session',
                'occurred_at' => now()->toISOString(),
                'payload' => ['watch_ms' => 1200],
            ],
            [
                'X-Embed-Slug' => 'player-embed',
                'Origin' => 'https://allowed.test',
            ],
        );
        $eventIngest->assertCreated();

        $this->assertDatabaseHas('analytics_events', [
            'team_id' => $team->id,
            'video_id' => $video->id,
            'event_name' => 'video_view',
        ]);
    }

    public function test_admin_listing_is_scoped_to_user_teams(): void
    {
        [$myTeam, $owner] = $this->createTeamWithOwner();
        [$otherTeam] = $this->createTeamWithOwner();

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/admin/videos?team_id={$otherTeam->id}");
        $response->assertForbidden();

        Video::query()->create([
            'team_id' => $myTeam->id,
            'title' => 'My Team Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $ownListing = $this->getJson("/api/v1/admin/videos?team_id={$myTeam->id}");
        $ownListing->assertOk()->assertJsonPath('data.0.team_id', $myTeam->id);
    }

    public function test_admin_can_issue_team_token_for_embed_calls(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $tokenResponse = $this->postJson("/api/v1/admin/teams/{$team->id}/tokens", [
            'name' => 'embed-token',
            'expires_in_days' => 14,
        ]);

        $tokenResponse->assertCreated();
        $token = $tokenResponse->json('token');
        $this->assertIsString($token);

        Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Token Feed Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        Embed::query()->create([
            'team_id' => $team->id,
            'name' => 'Token Embed',
            'type' => 'vertical_feed',
            'slug' => 'token-embed',
            'signed_key' => hash('sha256', 'token-embed'),
            'is_active' => true,
        ]);

        $feedResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/player/feed?embed_slug=token-embed');

        $feedResponse->assertOk();
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
