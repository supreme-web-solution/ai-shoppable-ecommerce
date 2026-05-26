<?php

namespace Tests\Feature\Api\V1;

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

class CheckoutRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_hybrid_checkout_uses_native_when_stripe_is_enabled(): void
    {
        [$team, $cart, $embed] = $this->createCheckoutFixture([
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

        $response = $this->postJson(
            '/api/v1/player/checkout',
            [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
                'checkout_mode' => 'hybrid',
            ],
            $this->embedHeaders($embed->slug),
        );

        $response->assertCreated()
            ->assertJsonPath('mode', 'native')
            ->assertJsonPath('provider', 'stripe')
            ->assertJsonPath('order.status', 'pending')
            ->assertJsonPath('checkout_url', fn ($value) => is_string($value) && str_contains($value, '/checkout/'));
    }

    public function test_hybrid_checkout_requires_native_payment_provider_when_external_not_enabled(): void
    {
        [$team, $cart, $embed] = $this->createCheckoutFixture([
            'checkout_mode' => 'hybrid',
            'external_provider' => 'none',
        ]);

        $response = $this->postJson(
            '/api/v1/player/checkout',
            [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
                'checkout_mode' => 'hybrid',
            ],
            $this->embedHeaders($embed->slug),
        );

        $response->assertStatus(422)
            ->assertJsonPath('mode', 'native_unavailable')
            ->assertJsonPath('settings_url', '/settings/integrations');
    }

    public function test_native_checkout_allows_repeat_checkout_for_same_embed_session(): void
    {
        [$team, $cart, $embed] = $this->createCheckoutFixture([
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

        Cart::query()->create([
            'team_id' => $team->id,
            'session_key' => $cart->session_key,
            'status' => 'checked_out',
            'checkout_mode' => 'native',
            'currency' => 'USD',
            'total_amount' => 25,
        ]);

        $response = $this->postJson(
            '/api/v1/player/checkout',
            [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
                'checkout_mode' => 'hybrid',
            ],
            $this->embedHeaders($embed->slug),
        );

        $response->assertCreated()
            ->assertJsonPath('mode', 'native')
            ->assertJsonPath('order.cart_id', $cart->id)
            ->assertJsonPath('order.status', 'pending');

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'status' => 'active',
            'checkout_mode' => 'native',
        ]);
    }

    public function test_native_checkout_page_can_start_stripe_payment_session(): void
    {
        [$team, $cart, $embed] = $this->createCheckoutFixture([
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

        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            ]),
        ]);

        $checkoutResponse = $this->postJson(
            '/api/v1/player/checkout',
            [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
                'checkout_mode' => 'hybrid',
            ],
            $this->embedHeaders($embed->slug),
        );

        $checkoutResponse->assertCreated();

        $orderId = $checkoutResponse->json('order.id');
        $order = Order::query()->findOrFail($orderId);

        $response = $this->postJson(
            "/api/v1/player/checkout/orders/{$order->id}/start-payment",
            ['token' => data_get($order->metadata, 'checkout_token')],
            $this->embedHeaders($embed->slug),
        );

        $response->assertOk()
            ->assertJsonPath('provider', 'stripe')
            ->assertJsonPath('provider_session_id', 'cs_test_123')
            ->assertJsonPath('checkout_url', 'https://checkout.stripe.com/c/pay/cs_test_123');
    }

    public function test_stripe_webhook_marks_pending_native_order_paid(): void
    {
        [$team, $cart, $embed] = $this->createCheckoutFixture([
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

        $checkoutResponse = $this->postJson(
            '/api/v1/player/checkout',
            [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
                'checkout_mode' => 'hybrid',
            ],
            $this->embedHeaders($embed->slug),
        );

        $checkoutResponse->assertCreated();

        $order = Order::query()->findOrFail($checkoutResponse->json('order.id'));

        $this->postJson('/api/v1/integrations/stripe/webhook', [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_paid',
                    'metadata' => [
                        'order_id' => (string) $order->id,
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'payment_reference' => 'cs_test_paid',
        ]);
        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'status' => 'checked_out',
        ]);
    }

    public function test_hybrid_checkout_uses_external_when_shopify_is_enabled(): void
    {
        [$team, $cart, $embed] = $this->createCheckoutFixture([
            'checkout_mode' => 'hybrid',
            'external_provider' => 'shopify',
            'settings' => [
                'integrations' => [
                    'shopify' => [
                        'enabled' => true,
                        'shop_url' => 'demo-store.myshopify.com',
                        'access_token' => 'shpat_test_token',
                    ],
                ],
            ],
        ]);

        $response = $this->postJson(
            '/api/v1/player/checkout',
            [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
                'checkout_mode' => 'hybrid',
            ],
            $this->embedHeaders($embed->slug),
        );

        $response->assertCreated()
            ->assertJsonPath('mode', 'external')
            ->assertJsonPath('provider', 'shopify')
            ->assertJsonPath('checkout_url', fn ($value) => is_string($value) && str_contains($value, 'checkout'));
    }

    /**
     * @param  array<string, mixed>  $teamOverrides
     * @return array{0: Team, 1: Cart, 2: Embed}
     */
    protected function createCheckoutFixture(array $teamOverrides = []): array
    {
        $owner = User::factory()->create();

        $team = Team::query()->create(array_merge([
            'owner_user_id' => $owner->id,
            'name' => 'Checkout Team',
            'slug' => 'checkout-team',
            'checkout_mode' => 'hybrid',
            'external_provider' => 'none',
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

        return [$team, $cart, $embed];
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
