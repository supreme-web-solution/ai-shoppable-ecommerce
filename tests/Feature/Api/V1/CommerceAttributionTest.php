<?php

namespace Tests\Feature\Api\V1;

use App\Models\AnalyticsEvent;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Embed;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use App\Services\Checkout\NativePaymentConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommerceAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_item_stores_video_attribution_metadata(): void
    {
        [$team, $video, $product, $embed] = $this->createPlayerFixture();

        $response = $this->postJson(
            '/api/v1/player/cart/items',
            [
                'team_id' => $team->id,
                'session_key' => 'attr-session',
                'product_id' => $product->id,
                'quantity' => 1,
                'video_id' => $video->id,
                'video_product_tag_id' => 42,
                'starts_at_ms' => 1500,
            ],
            $this->embedHeaders($embed->slug),
        );

        $response->assertOk();

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
        ]);

        $item = CartItem::query()->firstOrFail();

        $this->assertSame($video->id, data_get($item->metadata, 'video_id'));
        $this->assertSame(42, data_get($item->metadata, 'video_product_tag_id'));
        $this->assertSame(1500, data_get($item->metadata, 'starts_at_ms'));
    }

    public function test_native_checkout_copies_attribution_to_order_and_fires_checkout_started(): void
    {
        [$team, $video, $product, $embed, $cart] = $this->createCartFixture([
            'checkout_mode' => 'hybrid',
            'external_provider' => 'none',
            'settings' => [
                'integrations' => [
                    'stripe' => [
                        'enabled' => true,
                        'publishable_key' => 'pk_test_123',
                        'secret_key' => 'sk_test_123',
                    ],
                ],
            ],
        ]);

        CartItem::query()->where('cart_id', $cart->id)->update([
            'metadata' => [
                'video_id' => $video->id,
                'starts_at_ms' => 900,
            ],
        ]);

        $response = $this->postJson(
            '/api/v1/player/checkout',
            [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
                'checkout_mode' => 'hybrid',
                'video_id' => $video->id,
            ],
            $this->embedHeaders($embed->slug),
        );

        $response->assertCreated()
            ->assertJsonPath('mode', 'native');

        $orderId = (int) $response->json('order.id');
        $order = Order::query()->findOrFail($orderId);

        $this->assertSame($video->id, data_get($order->metadata, 'attribution.video_id'));
        $this->assertSame($video->id, data_get($order->items->first()->metadata, 'video_id'));

        $this->assertDatabaseHas('analytics_events', [
            'team_id' => $team->id,
            'video_id' => $video->id,
            'event_name' => 'checkout_started',
        ]);
    }

    public function test_marking_order_paid_records_checkout_completed_with_revenue(): void
    {
        [$team, $video, $product, $embed, $cart] = $this->createCartFixture();

        $order = Order::query()->create([
            'team_id' => $team->id,
            'cart_id' => $cart->id,
            'order_number' => 'ORD-TEST1234',
            'status' => 'pending',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
            'currency' => 'USD',
            'subtotal_amount' => 25,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 25,
            'metadata' => [
                'attribution' => [
                    'video_id' => $video->id,
                    'session_key' => $cart->session_key,
                    'lines' => [],
                ],
                'payment_provider' => 'stripe',
            ],
            'ordered_at' => now(),
        ]);

        app(NativePaymentConfirmationService::class)->markOrderPaid(
            $order,
            'stripe',
            'cs_test_paid_attr',
        );

        $this->assertDatabaseHas('analytics_events', [
            'team_id' => $team->id,
            'video_id' => $video->id,
            'event_name' => 'checkout_completed',
        ]);

        $event = AnalyticsEvent::query()
            ->where('event_name', 'checkout_completed')
            ->firstOrFail();

        $this->assertSame(25.0, (float) data_get($event->payload, 'total_amount'));
        $this->assertSame($order->id, data_get($event->payload, 'order_id'));
    }

    public function test_analytics_summary_includes_revenue_and_conversion_stats(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Best Seller Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $metricDate = now()->toDateString();

        foreach (range(1, 10) as $i) {
            AnalyticsEvent::query()->create([
                'team_id' => $team->id,
                'video_id' => $video->id,
                'event_name' => 'video_view',
                'source' => 'embed_player',
                'platform' => 'web_embed',
                'session_key' => "view-{$i}",
                'occurred_at' => now(),
            ]);
        }

        AnalyticsEvent::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'event_name' => 'add_to_cart',
            'source' => 'embed_player',
            'platform' => 'web_embed',
            'session_key' => 'cart-1',
            'occurred_at' => now(),
        ]);

        AnalyticsEvent::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'event_name' => 'checkout_completed',
            'source' => 'checkout_api',
            'platform' => 'web_embed',
            'session_key' => 'checkout-1',
            'payload' => [
                'order_id' => 99,
                'total_amount' => 50,
            ],
            'occurred_at' => now(),
        ]);

        Order::query()->create([
            'team_id' => $team->id,
            'order_number' => 'ORD-REV100',
            'status' => 'paid',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
            'currency' => 'USD',
            'subtotal_amount' => 50,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 50,
            'metadata' => [
                'attribution' => [
                    'video_id' => $video->id,
                    'lines' => [],
                ],
            ],
            'ordered_at' => now(),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson(
            "/api/v1/analytics/summary?team_id={$team->id}&from={$metricDate}&to={$metricDate}",
        );

        $response->assertOk();
        $response->assertJsonPath('commerce_roi.total_revenue', 50);
        $response->assertJsonPath('commerce_roi.paid_orders', 1);
        $response->assertJsonPath('commerce_roi.funnel.video_views', 10);
        $response->assertJsonPath('commerce_roi.funnel.add_to_cart', 1);
        $response->assertJsonPath('commerce_roi.funnel.checkouts_completed', 1);
        $response->assertJsonPath('top_videos_by_revenue.0.video_id', $video->id);
        $response->assertJsonPath('top_videos_by_revenue.0.revenue', 50);
        $response->assertJsonPath('video_conversion.0.video_id', $video->id);
        $response->assertJsonPath('video_conversion.0.revenue', 50);
        $response->assertJsonPath('video_conversion.0.views', 10);
    }

    public function test_external_checkout_records_checkout_external_redirect_event(): void
    {
        [$team, $video, $product, $embed, $cart] = $this->createCartFixture([
            'checkout_mode' => 'external',
            'external_provider' => 'shopify',
            'settings' => [
                'integrations' => [
                    'shopify' => [
                        'enabled' => true,
                        'shop_url' => 'demo-store.myshopify.com',
                        'client_id' => 'test-client-id',
                        'client_secret' => 'test-client-secret',
                    ],
                ],
            ],
        ], 'shopify');

        CartItem::query()->where('cart_id', $cart->id)->update([
            'metadata' => ['video_id' => $video->id],
        ]);

        $response = $this->postJson(
            '/api/v1/player/checkout',
            [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
                'checkout_mode' => 'hybrid',
                'video_id' => $video->id,
            ],
            $this->embedHeaders($embed->slug),
        );

        $response->assertCreated()
            ->assertJsonPath('mode', 'external');

        $this->assertDatabaseHas('analytics_events', [
            'team_id' => $team->id,
            'video_id' => $video->id,
            'event_name' => 'checkout_external_redirect',
        ]);
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

    /**
     * @return array{0: Team, 1: Video, 2: Product, 3: Embed}
     */
    protected function createPlayerFixture(): array
    {
        $owner = User::factory()->create();

        $team = Team::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Player Team',
            'slug' => 'player-team-'.fake()->unique()->slug(),
            'checkout_mode' => 'hybrid',
            'external_provider' => 'none',
        ]);

        $team->users()->attach($owner->id, ['role' => 'owner']);
        $owner->update(['team_id' => $team->id]);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Attr Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $product = Product::query()->create([
            'team_id' => $team->id,
            'title' => 'Attr Product',
            'slug' => 'attr-product',
            'source' => 'native',
            'currency' => 'USD',
            'price' => 25,
        ]);

        $embed = Embed::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'name' => 'Attr Embed',
            'type' => 'vertical_feed',
            'slug' => 'attr-embed-'.fake()->unique()->slug(),
            'signed_key' => hash('sha256', fake()->uuid()),
            'is_active' => true,
            'allowed_domains' => ['allowed.test'],
        ]);

        return [$team, $video, $product, $embed];
    }

    /**
     * @param  array<string, mixed>  $teamOverrides
     * @return array{0: Team, 1: Video, 2: Product, 3: Embed, 4: Cart}
     */
    protected function createCartFixture(array $teamOverrides = [], string $productSource = 'native'): array
    {
        $owner = User::factory()->create();

        $team = Team::query()->create(array_merge([
            'owner_user_id' => $owner->id,
            'name' => 'Checkout Team',
            'slug' => 'checkout-team-'.fake()->unique()->slug(),
            'checkout_mode' => 'hybrid',
            'external_provider' => 'none',
        ], $teamOverrides));

        $team->users()->attach($owner->id, ['role' => 'owner']);
        $owner->update(['team_id' => $team->id]);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Attr Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $product = Product::query()->create([
            'team_id' => $team->id,
            'title' => 'Attr Product',
            'slug' => 'attr-product-'.fake()->unique()->slug(),
            'source' => $productSource,
            'currency' => 'USD',
            'price' => 25,
            'external_id' => $productSource === 'shopify' ? '8932350165149' : null,
        ]);

        if ($productSource === 'shopify') {
            ProductVariant::query()->create([
                'team_id' => $team->id,
                'product_id' => $product->id,
                'external_id' => '8932350165149',
                'title' => 'Default',
                'sku' => 'SH-1',
                'price' => 25,
                'inventory' => 10,
                'is_default' => true,
            ]);
        }

        $embed = Embed::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'name' => 'Attr Embed',
            'type' => 'vertical_feed',
            'slug' => 'attr-embed-'.fake()->unique()->slug(),
            'signed_key' => hash('sha256', fake()->uuid()),
            'is_active' => true,
            'allowed_domains' => ['allowed.test'],
        ]);

        $cart = Cart::query()->create([
            'team_id' => $team->id,
            'session_key' => 'attr-checkout-session',
            'status' => 'active',
            'currency' => 'USD',
            'total_amount' => 25,
        ]);

        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 25,
            'line_total' => 25,
        ]);

        return [$team, $video, $product, $embed, $cart];
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
