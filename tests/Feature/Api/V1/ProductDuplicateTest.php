<?php

namespace Tests\Feature\Api\V1;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductDuplicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_duplicate_product_with_variants(): void
    {
        $owner = User::factory()->create();
        $team = Team::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Duplicate Team',
            'slug' => 'duplicate-team',
        ]);
        $owner->update(['team_id' => $team->id]);

        Sanctum::actingAs($owner);

        $product = Product::query()->create([
            'team_id' => $team->id,
            'external_id' => 'shopify-99',
            'source' => 'shopify',
            'title' => 'Jamalia Ball',
            'slug' => 'jamalia-ball',
            'description' => 'Nice cap',
            'image_url' => 'https://cdn.example.com/cap.png',
            'currency' => 'USD',
            'price' => 371,
            'sale_price' => 350,
            'sku' => 'CAP-001',
            'inventory' => 12,
            'is_active' => true,
        ]);

        ProductVariant::query()->create([
            'team_id' => $team->id,
            'product_id' => $product->id,
            'title' => 'Default',
            'sku' => 'CAP-001-V1',
            'price' => 371,
            'inventory' => 12,
            'is_default' => true,
        ]);

        $response = $this->postJson("/api/v1/admin/products/{$product->id}/duplicate", [
            'team_id' => $team->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Jamalia Ball (copy)')
            ->assertJsonPath('data.slug', 'jamalia-ball-copy')
            ->assertJsonPath('data.source', 'native')
            ->assertJsonPath('data.sku', 'CAP-001-copy');

        $copyId = (int) $response->json('data.id');

        $this->assertDatabaseHas('products', [
            'id' => $copyId,
            'team_id' => $team->id,
            'title' => 'Jamalia Ball (copy)',
            'slug' => 'jamalia-ball-copy',
            'source' => 'native',
            'external_id' => null,
        ]);

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $copyId,
            'sku' => 'CAP-001-V1-copy',
        ]);

        $this->assertSame(2, Product::query()->where('team_id', $team->id)->count());
    }
}
