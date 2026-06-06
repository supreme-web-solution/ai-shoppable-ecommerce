<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Embed;
use App\Models\Order;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutSuccessPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_stores_embed_return_context_from_header(): void
    {
        [$team, $cart, $embed, $video] = $this->createFixture();

        $response = $this->postJson(
            '/api/v1/player/checkout',
            [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
                'checkout_mode' => 'hybrid',
                'video_id' => $video->id,
                'return_url' => 'https://allowed.test/embed/'.$embed->slug.'?video='.$video->id,
            ],
            $this->embedHeaders($embed->slug),
        );

        $response->assertCreated();

        $order = Order::query()->findOrFail($response->json('order.id'));

        $this->assertSame($embed->slug, data_get($order->metadata, 'attribution.embed_slug'));
        $this->assertSame($video->id, data_get($order->metadata, 'attribution.video_id'));
        $this->assertSame(
            'https://allowed.test/embed/'.$embed->slug.'?video='.$video->id,
            data_get($order->metadata, 'attribution.return_url'),
        );
    }

    public function test_paid_checkout_success_page_exposes_return_and_receipt_urls(): void
    {
        [$team, $cart, $embed, $video] = $this->createFixture();

        $checkoutResponse = $this->postJson(
            '/api/v1/player/checkout',
            [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
                'checkout_mode' => 'hybrid',
                'video_id' => $video->id,
                'return_url' => 'https://allowed.test/embed/'.$embed->slug.'?video='.$video->id,
            ],
            $this->embedHeaders($embed->slug),
        );

        $order = Order::query()->findOrFail($checkoutResponse->json('order.id'));
        $token = (string) data_get($order->metadata, 'checkout_token');

        $order->update([
            'status' => 'paid',
            'payment_reference' => 'cs_test_return',
            'metadata' => array_merge((array) $order->metadata, [
                'payment_provider' => 'stripe',
                'paid_confirmed_at' => now()->toIso8601String(),
            ]),
        ]);

        $this->get("/checkout/{$order->id}/{$token}?payment=success&session_id=cs_test_return")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('checkout/Show')
                ->where('returnUrl', 'https://allowed.test/embed/'.$embed->slug.'?video='.$video->id)
                ->where('receiptUrl', route('checkout.receipt', ['order' => $order, 'token' => $token]))
                ->where('order.status', 'paid'));
    }

    public function test_receipt_download_is_available_for_paid_orders(): void
    {
        [$team, $cart, $embed] = $this->createFixture();

        $order = Order::query()->create([
            'team_id' => $team->id,
            'cart_id' => $cart->id,
            'order_number' => 'ORD-TESTRECEIPT',
            'status' => 'paid',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
            'currency' => 'USD',
            'subtotal_amount' => 25,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 25,
            'metadata' => [
                'checkout_token' => 'receipt-token',
                'payment_provider' => 'stripe',
                'paid_confirmed_at' => now()->toIso8601String(),
            ],
            'ordered_at' => now(),
        ]);

        $product = Product::query()->where('team_id', $team->id)->firstOrFail();

        $order->items()->create([
            'product_id' => $product->id,
            'title' => 'Checkout Product',
            'quantity' => 1,
            'unit_price' => 25,
            'line_total' => 25,
        ]);

        $this->get("/checkout/{$order->id}/receipt-token/receipt")
            ->assertOk()
            ->assertHeader('content-disposition')
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_stripe_return_marks_order_paid_and_shows_success_screen(): void
    {
        [$team, $cart, $embed, $video] = $this->createFixture([
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

        $checkoutResponse = $this->postJson(
            '/api/v1/player/checkout',
            [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
                'checkout_mode' => 'hybrid',
                'video_id' => $video->id,
            ],
            $this->embedHeaders($embed->slug),
        );

        $order = Order::query()->findOrFail($checkoutResponse->json('order.id'));
        $order->update([
            'payment_reference' => 'cs_test_return',
            'metadata' => array_merge((array) $order->metadata, [
                'payment_provider' => 'stripe',
            ]),
        ]);

        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions/cs_test_return' => Http::response([
                'id' => 'cs_test_return',
                'payment_status' => 'paid',
                'metadata' => [
                    'order_id' => (string) $order->id,
                ],
            ]),
        ]);

        $token = (string) data_get($order->metadata, 'checkout_token');

        $this->get("/checkout/{$order->id}/{$token}?payment=success&session_id=cs_test_return")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('checkout/Show')
                ->where('order.status', 'paid')
                ->where('returnUrl', url('/embed/'.$embed->slug.'?video='.$video->id)));

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
        ]);
    }

    public function test_pending_checkout_can_update_item_quantity(): void
    {
        [$team, $cart, $embed, $video] = $this->createFixture();

        $checkoutResponse = $this->postJson(
            '/api/v1/player/checkout',
            [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
                'checkout_mode' => 'hybrid',
                'video_id' => $video->id,
            ],
            $this->embedHeaders($embed->slug),
        );

        $order = Order::query()->findOrFail($checkoutResponse->json('order.id'));
        $item = $order->items()->firstOrFail();
        $token = (string) data_get($order->metadata, 'checkout_token');

        $this->patchJson("/api/v1/player/checkout/orders/{$order->id}/items/{$item->id}", [
            'token' => $token,
            'quantity' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('order.items.0.quantity', 3)
            ->assertJsonPath('order.total_amount', '75.00');

        $order->refresh();
        $cart->refresh();

        $this->assertSame('75.00', (string) $order->total_amount);
        $this->assertSame('75.00', (string) $cart->total_amount);
        $this->assertSame(3, (int) $cart->items()->firstOrFail()->quantity);
    }

    /**
     * @param  array<string, mixed>  $teamOverrides
     * @return array{0: Team, 1: Cart, 2: Embed, 3: Video}
     */
    protected function createFixture(array $teamOverrides = []): array
    {
        $owner = User::factory()->create();

        $team = Team::query()->create(array_merge([
            'owner_user_id' => $owner->id,
            'name' => 'Checkout Team',
            'slug' => 'checkout-team',
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
        ], $teamOverrides));

        $team->users()->attach($owner->id, ['role' => 'owner']);
        $owner->update(['team_id' => $team->id]);

        $product = Product::query()->create([
            'team_id' => $team->id,
            'title' => 'Checkout Product',
            'slug' => 'checkout-product',
            'source' => 'native',
            'currency' => 'USD',
            'price' => 25,
        ]);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Checkout Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $embed = Embed::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'name' => 'Checkout Embed',
            'type' => 'vertical_feed',
            'slug' => 'checkout-embed',
            'signed_key' => hash('sha256', 'checkout-embed'),
            'is_active' => true,
            'allowed_domains' => ['allowed.test'],
        ]);

        $cart = Cart::query()->create([
            'team_id' => $team->id,
            'session_key' => 'checkout-session',
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

        return [$team, $cart, $embed, $video];
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
