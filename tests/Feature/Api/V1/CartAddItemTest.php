<?php

namespace Tests\Feature\Api\V1;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Embed;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartAddItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_item_falls_back_when_variant_belongs_to_another_product(): void
    {
        [$team, $embed] = $this->createTeamWithEmbed();

        $firstProduct = Product::query()->create([
            'team_id' => $team->id,
            'title' => 'Local Tee',
            'slug' => 'local-tee',
            'source' => 'native',
            'currency' => 'USD',
            'price' => 20,
        ]);

        $firstVariant = ProductVariant::query()->create([
            'team_id' => $team->id,
            'product_id' => $firstProduct->id,
            'title' => 'Small',
            'price' => 20,
            'inventory' => 5,
            'is_default' => true,
        ]);

        $secondProduct = Product::query()->create([
            'team_id' => $team->id,
            'title' => 'Shopify Hoodie',
            'slug' => 'shopify-hoodie',
            'source' => 'shopify',
            'currency' => 'USD',
            'price' => 308,
        ]);

        ProductVariant::query()->create([
            'team_id' => $team->id,
            'product_id' => $secondProduct->id,
            'external_id' => '8932350165149',
            'title' => 'Default',
            'price' => 308,
            'inventory' => 10,
            'is_default' => true,
        ]);

        $response = $this->postJson(
            '/api/v1/player/cart/items',
            [
                'team_id' => $team->id,
                'session_key' => 'embed-session',
                'product_id' => $secondProduct->id,
                'product_variant_id' => $firstVariant->id,
                'quantity' => 1,
            ],
            $this->embedHeaders($embed->slug),
        );

        $response->assertOk()
            ->assertJsonPath('data.items.0.product_id', $secondProduct->id)
            ->assertJsonPath('data.items.0.product_variant_id', fn ($value) => $value !== $firstVariant->id);
    }

    /**
     * @return array{0: Team, 1: Embed}
     */
    protected function createTeamWithEmbed(): array
    {
        $owner = User::factory()->create();

        $team = Team::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Cart Team',
            'slug' => 'cart-team',
        ]);

        $team->users()->attach($owner->id, ['role' => 'owner']);
        $owner->update(['team_id' => $team->id]);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Cart Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $embed = Embed::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'name' => 'Cart Embed',
            'type' => 'vertical_feed',
            'slug' => 'cart-embed',
            'signed_key' => hash('sha256', 'cart-embed'),
            'is_active' => true,
            'allowed_domains' => ['allowed.test'],
        ]);

        return [$team, $embed];
    }

    /**
     * @return array<string, string>
     */
    protected function embedHeaders(string $slug): array
    {
        return [
            'X-Embed-Slug' => $slug,
            'Origin' => 'https://allowed.test',
        ];
    }
}
