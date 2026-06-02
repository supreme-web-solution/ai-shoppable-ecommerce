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
        Http::fake([
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
                && data_get($payload, 'background') === null
                && data_get($payload, 'motion_prompt') === null;
        });
    }

    public function test_avatar_generation_can_send_product_placement_settings_to_heygen(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        config([
            'services.heygen.api_key' => 'test-heygen-key',
            'services.heygen.watermark_enabled' => true,
        ]);

        Http::fake([
            'https://api.heygen.com/v3/videos' => Http::response([
                'data' => [
                    'video_id' => 'v_placement',
                    'status' => 'pending',
                ],
            ]),
        ]);

        Sanctum::actingAs($owner);

        app(AiAvatarVideoService::class)->submit([
            'team_id' => $team->id,
            'title' => 'Placement Demo',
            'script' => 'This product is amazing.',
            'language' => 'en',
            'avatar_id' => 'look_123',
            'voice_id' => 'voice_123',
            'product_placement_enabled' => true,
            'product_placement_image_url' => 'https://cdn.example.com/product-bottle.png',
            'product_placement_position' => 'top_right',
            'product_placement_scale' => 0.45,
            'product_placement_opacity' => 0.9,
            'product_placement_offset_x' => 0.05,
            'product_placement_offset_y' => -0.03,
            'product_placement_motion_prompt' => 'Hold the bottle naturally and point to it.',
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.heygen.com/v3/videos'
                && data_get($payload, 'watermark.image.type') === 'url'
                && data_get($payload, 'watermark.image.url') === 'https://cdn.example.com/product-bottle.png'
                && data_get($payload, 'watermark.placement.position') === 'top_right'
                && (float) data_get($payload, 'watermark.scale') === 0.45
                && (float) data_get($payload, 'watermark.opacity') === 0.9
                && (float) data_get($payload, 'watermark.placement.offset_x') === 0.05
                && (float) data_get($payload, 'watermark.placement.offset_y') === -0.03
                && data_get($payload, 'motion_prompt') === 'Hold the bottle naturally and point to it.';
        });
    }

    public function test_avatar_generation_omits_watermark_when_disabled_in_config(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        config([
            'services.heygen.api_key' => 'test-heygen-key',
            'services.heygen.watermark_enabled' => false,
        ]);

        Http::fake([
            'https://api.heygen.com/v3/videos' => Http::response([
                'data' => [
                    'video_id' => 'v_no_watermark',
                    'status' => 'pending',
                ],
            ]),
        ]);

        Sanctum::actingAs($owner);

        app(AiAvatarVideoService::class)->submit([
            'team_id' => $team->id,
            'title' => 'No Watermark',
            'script' => 'This product is amazing.',
            'language' => 'en',
            'avatar_id' => 'look_123',
            'voice_id' => 'voice_123',
            'product_placement_enabled' => true,
            'product_placement_image_url' => 'https://cdn.example.com/product-bottle.png',
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.heygen.com/v3/videos'
                && ! isset($payload['watermark'])
                && data_get($payload, 'motion_prompt') === null;
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
