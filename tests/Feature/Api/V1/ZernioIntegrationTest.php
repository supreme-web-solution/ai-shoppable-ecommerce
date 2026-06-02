<?php

namespace Tests\Feature\Api\V1;

use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ZernioIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_zernio_routes_return_404_when_disabled(): void
    {
        config([
            'services.zernio.enabled' => false,
            'services.zernio.api_key' => '',
        ]);

        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/admin/zernio/status?team_id={$team->id}")
            ->assertNotFound();
    }

    public function test_shop_link_creates_vertical_embed_and_shop_route(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.api_key' => 'test-zernio-key',
        ]);

        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Summer Drop',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
            'playback_url' => 'https://cdn.example/video.mp4',
        ]);

        $response = $this->getJson("/api/v1/admin/zernio/shop-link?team_id={$team->id}&video_id={$video->id}");
        $response->assertOk();
        $response->assertJsonStructure(['shop_url', 'embed_slug', 'type']);

        $shopUrl = $response->json('shop_url');
        $slug = $response->json('embed_slug');

        $this->assertStringContainsString('/shop/', $shopUrl);

        $this->get(route('shop.show', ['slug' => $slug]))->assertOk();
    }

    public function test_shop_link_rejects_unpublished_video(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.api_key' => 'test-zernio-key',
        ]);

        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Draft Video',
            'source' => 'uploaded',
            'status' => 'ready',
            'visibility' => 'public',
            'playback_url' => 'https://cdn.example/video.mp4',
        ]);

        $this->getJson("/api/v1/admin/zernio/shop-link?team_id={$team->id}&video_id={$video->id}")
            ->assertStatus(422);
    }

    public function test_publish_calls_zernio_with_shop_caption(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.api_key' => 'test-zernio-key',
        ]);

        Http::fake([
            'https://zernio.com/api/v1/profiles' => Http::response([
                'profile' => ['_id' => 'prof_test'],
            ], 201),
            'https://zernio.com/api/v1/posts' => Http::response([
                'post' => ['_id' => 'post_test', 'status' => 'published'],
            ], 201),
        ]);

        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Launch Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
            'playback_url' => 'https://cdn.example/launch.mp4',
        ]);

        $response = $this->postJson('/api/v1/admin/zernio/publish', [
            'team_id' => $team->id,
            'video_id' => $video->id,
            'publish_now' => true,
            'platforms' => [
                ['platform' => 'instagram', 'accountId' => 'acc_123'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('shop_url', fn ($url) => is_string($url) && str_contains($url, '/shop/'));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/posts')) {
                return false;
            }

            $body = $request->data();

            return str_contains((string) ($body['content'] ?? ''), '/shop/')
                && ($body['publishNow'] ?? false) === true;
        });

        $this->assertDatabaseHas('social_posts', [
            'team_id' => $team->id,
            'video_id' => $video->id,
            'status' => 'published',
        ]);
    }

    /**
     * @return array{0: Team, 1: User}
     */
    protected function createTeamWithOwner(): array
    {
        $user = User::factory()->create();
        $team = Team::query()->create([
            'name' => 'Test Team',
            'slug' => 'test-team-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        $team->users()->attach($user->id, ['role' => 'owner']);

        return [$team, $user];
    }
}
