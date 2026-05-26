<?php

namespace Tests\Feature\Api\V1;

use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiAvatarVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_avatar_generation_sends_product_image_as_heygen_background(): void
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
            'https://api.heygen.com/v3/videos/v_123' => Http::response([
                'data' => [
                    'status' => 'completed',
                    'video_url' => 'https://cdn.example.com/render.mp4',
                    'thumbnail_url' => 'https://cdn.example.com/render.jpg',
                    'duration' => 42,
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

        $this->postJson('/api/v1/admin/ai/avatar-videos', $input)->assertStatus(202)
            ->assertJsonPath('generation.type', 'avatar_video')
            ->assertJsonPath('video.metadata.product_ids.0', $product->id);

        app(AiAvatarVideoService::class)->submit($input);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.heygen.com/v3/videos'
                && data_get($payload, 'type') === 'avatar'
                && data_get($payload, 'avatar_id') === 'look_123'
                && data_get($payload, 'voice_id') === 'voice_123'
                && data_get($payload, 'aspect_ratio') === '9:16'
                && data_get($payload, 'background.type') === 'image'
                && data_get($payload, 'background.url') === 'https://cdn.example.com/camera-bag.jpg';
        });
    }

    public function test_avatar_generation_uploads_custom_visual_to_heygen_asset_background(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        config(['services.heygen.api_key' => 'test-heygen-key']);
        Storage::fake('public');

        $storedPath = UploadedFile::fake()
            ->image('ad-visual.png', 900, 1200)
            ->store('uploads/ai-visuals', 'public');

        Http::fake([
            'https://api.heygen.com/v3/assets' => Http::response([
                'data' => [
                    'asset_id' => 'asset_product_visual',
                    'url' => 'https://files.heygen.ai/assets/asset_product_visual.png',
                    'mime_type' => 'image/png',
                ],
            ]),
            'https://api.heygen.com/v3/videos' => Http::response([
                'data' => [
                    'video_id' => 'v_456',
                    'status' => 'pending',
                ],
            ]),
        ]);

        Sanctum::actingAs($owner);

        app(AiAvatarVideoService::class)->submit([
            'team_id' => $team->id,
            'title' => 'AI Presenter Demo',
            'script' => 'This is a shoppable product demo script for testing.',
            'language' => 'en',
            'avatar_id' => 'look_123',
            'voice_id' => 'voice_123',
            'ad_style' => 'avatar_beside_product',
            'visual_file_path' => Storage::disk('public')->path($storedPath),
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.heygen.com/v3/assets';
        });

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.heygen.com/v3/videos'
                && data_get($payload, 'background.type') === 'image'
                && data_get($payload, 'background.asset_id') === 'asset_product_visual'
                && data_get($payload, 'motion_prompt') !== null;
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
