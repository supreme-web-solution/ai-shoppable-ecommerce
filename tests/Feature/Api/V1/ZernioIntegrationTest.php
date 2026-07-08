<?php

namespace Tests\Feature\Api\V1;

use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use App\Services\Integrations\ZernioService;
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

        \App\Models\SocialAccount::query()->create([
            'team_id' => $team->id,
            'platform' => 'instagram',
            'zernio_account_id' => 'acc_123',
            'platform_username' => 'team_ig',
            'connected_at' => now(),
        ]);

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

    public function test_publish_adapts_twitter_caption_before_sending_to_zernio(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.api_key' => 'test-zernio-key',
        ]);

        Http::fake([
            'https://zernio.com/api/v1/posts' => Http::response([
                'post' => ['_id' => 'post_test', 'status' => 'published'],
            ], 201),
        ]);

        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        \App\Models\SocialAccount::query()->create([
            'team_id' => $team->id,
            'platform' => 'twitter',
            'zernio_account_id' => 'acc_tw',
            'platform_username' => 'team_tw',
            'connected_at' => now(),
        ]);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Launch Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
            'playback_url' => 'https://cdn.example/launch.mp4',
        ]);

        $longCaption = str_repeat('Word ', 120).'https://example.com/shop/demo';

        $response = $this->postJson('/api/v1/admin/zernio/publish', [
            'team_id' => $team->id,
            'video_id' => $video->id,
            'caption' => $longCaption,
            'publish_now' => true,
            'platforms' => [
                ['platform' => 'twitter', 'accountId' => 'acc_tw'],
            ],
        ]);

        $response->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/posts')) {
                return false;
            }

            $body = $request->data();
            $twitterContent = (string) ($body['platforms'][0]['content'] ?? $body['content'] ?? '');

            return ($body['platforms'][0]['platform'] ?? null) === 'twitter'
                && mb_strlen($twitterContent) < mb_strlen(str_repeat('Word ', 120).'https://example.com/shop/demo');
        });
    }

    public function test_publish_requires_media_for_instagram(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.api_key' => 'test-zernio-key',
        ]);

        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        \App\Models\SocialAccount::query()->create([
            'team_id' => $team->id,
            'platform' => 'instagram',
            'zernio_account_id' => 'acc_123',
            'platform_username' => 'team_ig',
            'connected_at' => now(),
        ]);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'No Media',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
            'playback_url' => null,
        ]);

        $this->postJson('/api/v1/admin/zernio/publish', [
            'team_id' => $team->id,
            'video_id' => $video->id,
            'publish_now' => true,
            'platforms' => [
                ['platform' => 'instagram', 'accountId' => 'acc_123'],
            ],
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Instagram requires a video or image attachment.']);
    }

    public function test_status_returns_local_social_accounts_only(): void
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

        \App\Models\SocialAccount::query()->create([
            'team_id' => $team->id,
            'platform' => 'instagram',
            'zernio_account_id' => 'acc_1',
            'zernio_profile_id' => 'prof_team_a',
            'platform_username' => 'team_a_ig',
            'connected_at' => now(),
        ]);

        Http::fake();

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/admin/zernio/status?team_id={$team->id}");
        $response->assertOk();
        $response->assertJsonPath('accounts.0._id', 'acc_1');
        $response->assertJsonCount(1, 'accounts');
        Http::assertNothingSent();
    }

    public function test_stale_profile_is_recreated_when_listing_remote_accounts(): void
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

        $accounts = app(ZernioService::class)->listAccounts($team->fresh());

        $this->assertCount(1, $accounts);
        $this->assertSame('acc_fresh', $accounts[0]['_id'] ?? null);
        $this->assertSame('prof_fresh', data_get($team->fresh()->settings, 'integrations.zernio.profile_id'));
        $this->assertSame(2, $accountCalls);
    }

    public function test_disconnect_account_calls_zernio_delete_and_clears_database(): void
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

        \App\Models\SocialAccount::query()->create([
            'team_id' => $team->id,
            'platform' => 'instagram',
            'zernio_account_id' => 'acc_123',
            'zernio_profile_id' => 'prof_team_a',
            'platform_username' => 'team_a_ig',
            'connected_at' => now(),
        ]);

        Http::fake([
            'https://zernio.com/api/v1/accounts/acc_123*' => Http::response([], 204),
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/admin/zernio/platforms/instagram?team_id={$team->id}")
            ->assertOk()
            ->assertJsonPath('disconnected', true)
            ->assertJsonCount(0, 'accounts');

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/accounts/acc_123')
                && ($request->data()['profileId'] ?? null) === 'prof_team_a';
        });

        $this->assertDatabaseMissing('social_accounts', [
            'team_id' => $team->id,
            'platform' => 'instagram',
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
