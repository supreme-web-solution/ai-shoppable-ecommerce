<?php

namespace Tests\Feature\Api\V1;

use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiAvatarVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_generate_ai_script(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $product = Product::query()->create([
            'team_id' => $team->id,
            'title' => 'Demo Product',
            'slug' => 'demo-product',
            'currency' => 'USD',
            'price' => 29.99,
            'source' => 'native',
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/admin/ai/scripts', [
            'team_id' => $team->id,
            'topic' => 'Summer drop',
            'tone' => 'engaging',
            'language' => 'en',
            'duration_seconds' => 45,
            'product_ids' => [$product->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'script')
            ->assertJsonPath('data.status', 'completed');

        $this->assertNotEmpty($response->json('data.output.full_script'));
    }

    public function test_admin_can_queue_avatar_video_generation(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        config(['services.heygen.api_key' => null]);

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/admin/ai/avatar-videos', [
            'team_id' => $team->id,
            'title' => 'AI Presenter Demo',
            'script' => 'This is a shoppable product demo script for testing.',
            'language' => 'en',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('generation.type', 'avatar_video')
            ->assertJsonPath('video.source', 'ai_generated')
            ->assertJsonPath('video.status', 'processing');
    }

    public function test_admin_can_fetch_cached_heygen_avatar_and_voice_options(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        config(['services.heygen.api_key' => 'test-heygen-key']);
        Cache::flush();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/v3/avatars/looks')) {
                return Http::response([
                    'data' => [[
                        'id' => 'look_123',
                        'name' => 'Product Presenter',
                        'avatar_type' => 'studio_avatar',
                        'gender' => 'female',
                        'preview_image_url' => 'https://cdn.example.com/avatar.jpg',
                        'default_voice_id' => 'voice_123',
                        'preferred_orientation' => 'portrait',
                        'supported_api_engines' => ['avatar_iv'],
                    ]],
                ]);
            }

            if (str_contains($request->url(), '/v3/voices')) {
                return Http::response([
                    'data' => [[
                        'voice_id' => 'voice_123',
                        'name' => 'Sara',
                        'language' => 'English',
                        'gender' => 'female',
                        'preview_audio_url' => 'https://cdn.example.com/voice.mp3',
                        'type' => 'public',
                    ]],
                ]);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/admin/ai/heygen-options?team_id={$team->id}")
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('avatars.0.id', 'look_123')
            ->assertJsonPath('avatars.0.default_voice_id', 'voice_123')
            ->assertJsonPath('voices.0.voice_id', 'voice_123')
            ->assertJsonPath('voices.0.preview_audio_url', 'https://cdn.example.com/voice.mp3');

        $this->getJson("/api/v1/admin/ai/heygen-options?team_id={$team->id}")
            ->assertOk();

        Http::assertSentCount(10);
    }

    public function test_avatar_generation_uses_presenter_only_heygen_payload(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $product = Product::query()->create([
            'team_id' => $team->id,
            'title' => 'Camera Bag',
            'slug' => 'camera-bag',
            'currency' => 'USD',
            'price' => 79.99,
            'source' => 'native',
            'image_url' => 'https://cdn.example.com/camera-bag.jpg',
            'is_active' => true,
        ]);

        config(['services.heygen.api_key' => 'test-heygen-key']);
        Cache::flush();

        Http::fake([
            'https://api.heygen.com/v3/avatars/looks/*' => Http::response([
                'data' => [
                    'id' => 'look_123',
                    'avatar_type' => 'studio_avatar',
                    'supported_api_engines' => ['avatar_iv'],
                ],
            ]),
            'https://api.heygen.com/v3/videos' => Http::response([
                'data' => [
                    'video_id' => 'v_123',
                    'status' => 'pending',
                ],
            ]),
        ]);

        Sanctum::actingAs($owner);

        $input = [
            'team_id' => $team->id,
            'title' => 'AI Presenter Demo',
            'script' => 'This is a shoppable product demo script for testing.',
            'language' => 'en',
            'avatar_id' => 'look_123',
            'voice_id' => 'voice_123',
            'product_ids' => [$product->id],
        ];

        app(AiAvatarVideoService::class)->submit($input);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.heygen.com/v3/videos'
                && data_get($payload, 'type') === 'avatar'
                && data_get($payload, 'avatar_id') === 'look_123'
                && data_get($payload, 'voice_id') === 'voice_123'
                && data_get($payload, 'aspect_ratio') === '9:16'
                && data_get($payload, 'background') === null;
        });
    }

    public function test_avatar_generation_throws_when_presenter_is_not_api_compatible(): void
    {
        config(['services.heygen.api_key' => 'test-heygen-key']);
        Cache::flush();

        Http::fake([
            'https://api.heygen.com/v3/avatars/looks/*' => Http::response([
                'data' => [
                    'id' => 'Daphne_public_6',
                    'avatar_type' => 'studio_avatar',
                    'supported_api_engines' => [],
                ],
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not compatible with HeyGen API');

        app(AiAvatarVideoService::class)->submit([
            'title' => 'Legacy Presenter Demo',
            'script' => 'This should fail before charging credits.',
            'avatar_id' => 'Daphne_public_6',
            'voice_id' => 'voice_123',
        ]);
    }

    public function test_avatar_generation_throws_when_heygen_rejects_request(): void
    {
        config(['services.heygen.api_key' => 'test-heygen-key']);
        Cache::flush();

        Http::fake([
            'https://api.heygen.com/v3/avatars/looks/*' => Http::response([
                'data' => [
                    'id' => 'look_123',
                    'avatar_type' => 'studio_avatar',
                    'supported_api_engines' => ['avatar_iv'],
                ],
            ]),
            'https://api.heygen.com/v3/videos' => Http::response([
                'error' => [
                    'message' => 'Voice is not available for this avatar.',
                ],
            ], 400),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Voice is not available');

        app(AiAvatarVideoService::class)->submit([
            'title' => 'Rejected Demo',
            'script' => 'This should fail loudly.',
            'avatar_id' => 'look_123',
            'voice_id' => 'voice_123',
        ]);
    }

    public function test_avatar_generation_can_send_custom_color_background_to_heygen(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        config(['services.heygen.api_key' => 'test-heygen-key']);
        Cache::flush();

        Http::fake([
            'https://api.heygen.com/v3/avatars/looks/*' => Http::response([
                'data' => [
                    'id' => 'look_123',
                    'avatar_type' => 'photo_avatar',
                    'supported_api_engines' => ['avatar_iv', 'avatar_v'],
                ],
            ]),
            'https://api.heygen.com/v3/videos' => Http::response([
                'data' => [
                    'video_id' => 'v_bg_color',
                    'status' => 'pending',
                ],
            ]),
        ]);

        Sanctum::actingAs($owner);

        app(AiAvatarVideoService::class)->submit([
            'team_id' => $team->id,
            'title' => 'Custom Background Demo',
            'script' => 'This presenter uses a custom solid background.',
            'language' => 'en',
            'avatar_id' => 'look_123',
            'voice_id' => 'voice_123',
            'custom_background_enabled' => true,
            'background_color' => '#F2EFEA',
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.heygen.com/v3/videos'
                && data_get($payload, 'background.type') === 'color'
                && data_get($payload, 'background.value') === '#f2efea'
                && data_get($payload, 'fit') === 'contain'
                && data_get($payload, 'remove_background') === true;
        });
    }

    public function test_avatar_generation_omits_background_when_custom_background_disabled(): void
    {
        config(['services.heygen.api_key' => 'test-heygen-key']);
        Cache::flush();

        Http::fake([
            'https://api.heygen.com/v3/avatars/looks/*' => Http::response([
                'data' => [
                    'id' => 'look_123',
                    'avatar_type' => 'studio_avatar',
                    'supported_api_engines' => ['avatar_iv'],
                ],
            ]),
            'https://api.heygen.com/v3/videos' => Http::response([
                'data' => [
                    'video_id' => 'v_default_bg',
                    'status' => 'pending',
                ],
            ]),
        ]);

        app(AiAvatarVideoService::class)->submit([
            'title' => 'Default Background Demo',
            'script' => 'Uses the avatar look default scene.',
            'avatar_id' => 'look_123',
            'voice_id' => 'voice_123',
            'custom_background_enabled' => false,
            'background_color' => '#f2efea',
        ]);

        Http::assertSent(function ($request): bool {
            return ! isset($request->data()['background']);
        });
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
