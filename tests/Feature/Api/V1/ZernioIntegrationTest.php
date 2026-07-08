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

    public function test_list_accounts_scopes_by_profile_id(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.api_key' => 'test-zernio-key',
        ]);

        [$team, $owner] = $this->createTeamWithOwner();
        $team->update([
            'settings' => [
                'integrations' => [
                    'zernio' => [
                        'profile_id' => 'prof_team_a',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://zernio.com/api/v1/accounts*' => function ($request) {
                $this->assertSame('prof_team_a', $request->data()['profileId'] ?? null);

                return Http::response([
                    'accounts' => [
                        [
                            '_id' => 'acc_1',
                            'platform' => 'instagram',
                            'username' => 'team_a_ig',
                            'profileId' => 'prof_team_a',
                        ],
                        [
                            '_id' => 'acc_other',
                            'platform' => 'facebook',
                            'username' => 'other_team',
                            'profileId' => 'prof_other',
                        ],
                    ],
                ]);
            },
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/admin/zernio/status?team_id={$team->id}");
        $response->assertOk();
        $response->assertJsonPath('accounts.0._id', 'acc_1');
        $response->assertJsonCount(1, 'accounts');
    }

    public function test_stale_profile_is_recreated_and_accounts_retried(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.api_key' => 'test-zernio-key',
        ]);

        [$team, $owner] = $this->createTeamWithOwner();
        $team->update([
            'settings' => [
                'integrations' => [
                    'zernio' => [
                        'profile_id' => 'prof_stale',
                    ],
                ],
            ],
        ]);

        $accountCalls = 0;

        Http::fake([
            'https://zernio.com/api/v1/accounts*' => function () use (&$accountCalls) {
                $accountCalls++;

                if ($accountCalls === 1) {
                    return Http::response([
                        'error' => ['message' => 'Profile not found or access denied'],
                    ], 404);
                }

                return Http::response([
                    'accounts' => [
                        [
                            '_id' => 'acc_fresh',
                            'platform' => 'instagram',
                            'username' => 'fresh_ig',
                            'profileId' => 'prof_fresh',
                        ],
                    ],
                ]);
            },
            'https://zernio.com/api/v1/profiles' => Http::response([
                'profile' => ['_id' => 'prof_fresh'],
            ], 201),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/admin/zernio/status?team_id={$team->id}");
        $response->assertOk();
        $response->assertJsonPath('profile_id', 'prof_fresh');
        $response->assertJsonPath('accounts.0._id', 'acc_fresh');
        $this->assertSame(2, $accountCalls);
    }

    public function test_disconnect_account_calls_zernio_delete(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.api_key' => 'test-zernio-key',
        ]);

        [$team, $owner] = $this->createTeamWithOwner();
        $team->update([
            'settings' => [
                'integrations' => [
                    'zernio' => [
                        'profile_id' => 'prof_team_a',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://zernio.com/api/v1/accounts/acc_123*' => Http::response([], 204),
            'https://zernio.com/api/v1/accounts*' => Http::response(['accounts' => []]),
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/admin/zernio/accounts/acc_123?team_id={$team->id}")
            ->assertOk()
            ->assertJsonPath('disconnected', true);

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/accounts/acc_123')
                && ($request->data()['profileId'] ?? null) === 'prof_team_a';
        });
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
