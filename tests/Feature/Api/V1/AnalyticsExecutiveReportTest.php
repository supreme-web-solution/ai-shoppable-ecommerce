<?php

namespace Tests\Feature\Api\V1;

use App\Models\AnalyticsEvent;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsExecutiveReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_includes_abandoned_carts_and_period_comparison(): void
    {
        [$team, $owner, $video] = $this->createStoreFixture();
        $metricDate = now()->toDateString();

        $cart = Cart::query()->create([
            'team_id' => $team->id,
            'session_key' => 'abandoned-session',
            'status' => 'active',
            'currency' => 'USD',
            'total_amount' => 40,
        ]);
        $cart->forceFill(['updated_at' => now()->subHours(2)])->save();

        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => Product::query()->where('team_id', $team->id)->value('id'),
            'quantity' => 1,
            'unit_price' => 40,
            'line_total' => 40,
            'metadata' => ['video_id' => $video->id],
        ]);

        AnalyticsEvent::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'event_name' => 'video_view',
            'source' => 'embed_player',
            'platform' => 'web_embed',
            'session_key' => 'view-1',
            'occurred_at' => now(),
        ]);

        Order::query()->create([
            'team_id' => $team->id,
            'order_number' => 'ORD-EXEC1',
            'status' => 'paid',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
            'currency' => 'USD',
            'subtotal_amount' => 80,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 80,
            'metadata' => ['attribution' => ['video_id' => $video->id, 'lines' => []]],
            'ordered_at' => now(),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson(
            "/api/v1/analytics/summary?team_id={$team->id}&from={$metricDate}&to={$metricDate}",
        );

        $response->assertOk();
        $response->assertJsonPath('abandoned_carts.count', 1);
        $response->assertJsonPath('abandoned_carts.recoverable_value', 40);
        $response->assertJsonPath('period_comparison.current.revenue', 80);
        $response->assertJsonPath('period_comparison.current.abandoned_carts', 1);
        $response->assertJsonStructure([
            'period_comparison' => [
                'current' => ['revenue', 'orders', 'views', 'checkouts', 'abandoned_carts'],
                'previous' => ['revenue', 'orders', 'views', 'checkouts', 'abandoned_carts'],
                'changes' => ['revenue_pct', 'orders_pct', 'views_pct', 'checkouts_pct', 'abandoned_carts_pct'],
            ],
        ]);
    }

    /**
     * @return array{0: Team, 1: User, 2: Video}
     */
    protected function createStoreFixture(): array
    {
        $owner = User::factory()->create();

        $team = Team::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Demo Store',
            'slug' => 'demo-store-'.fake()->unique()->slug(),
            'checkout_mode' => 'hybrid',
            'external_provider' => 'none',
        ]);

        $team->users()->attach($owner->id, ['role' => 'owner']);
        $owner->update(['team_id' => $team->id]);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Store Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        Product::query()->create([
            'team_id' => $team->id,
            'title' => 'Store Product',
            'slug' => 'store-product',
            'source' => 'native',
            'currency' => 'USD',
            'price' => 40,
        ]);

        return [$team, $owner, $video];
    }
}
