<?php

namespace Tests\Feature\Api\V1;

use App\Models\Team;
use App\Models\User;
use App\Services\CloudinaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ProductImageUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_product_image_to_cloudinary(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $cloudinary = Mockery::mock(CloudinaryService::class);
        $cloudinary->shouldReceive('uploadImage')
            ->once()
            ->andReturn([
                'public_id' => 'teams/'.$team->id.'/products/test-image',
                'secure_url' => 'https://res.cloudinary.com/demo/image/upload/v1/product.jpg',
                'used_mock' => false,
            ]);
        $this->app->instance(CloudinaryService::class, $cloudinary);

        $response = $this->postJson('/api/v1/admin/products/upload-image', [
            'team_id' => $team->id,
            'file' => UploadedFile::fake()->image('product.jpg', 400, 400),
        ]);

        $response->assertOk()
            ->assertJsonPath('image_url', 'https://res.cloudinary.com/demo/image/upload/v1/product.jpg');
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
