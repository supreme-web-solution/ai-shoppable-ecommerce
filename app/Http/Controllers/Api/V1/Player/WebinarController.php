<?php

namespace App\Http\Controllers\Api\V1\Player;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LiveShowResource;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Cart;
use App\Models\LiveShow;
use App\Models\LiveShowMessage;
use App\Models\LiveShowRegistration;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Team;
use App\Services\Ai\WebinarAssistantService;
use App\Services\Checkout\ExternalCheckoutService;
use App\Services\Checkout\NativeCheckoutService;
use App\Services\Checkout\TeamCheckoutResolver;
use App\Services\Webinars\WebinarOfferService;
use App\Services\Webinars\WebinarWatchProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebinarController extends Controller
{
    public function show(LiveShow $liveShow): JsonResponse
    {
        abort_if($liveShow->status === 'cancelled', 404);

        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];
        $settings['views_count'] = (int) ($settings['views_count'] ?? 0) + 1;
        $liveShow->forceFill(['settings' => $settings])->save();

        $liveShow->load(['featuredProducts', 'video', 'team'])->loadCount(['registrations', 'messages']);

        return response()->json([
            'data' => (new LiveShowResource($liveShow))->resolve(),
        ]);
    }

    public function checkoutOffer(
        Request $request,
        LiveShow $liveShow,
        int $productId,
        WebinarOfferService $offerService,
        TeamCheckoutResolver $checkoutResolver,
        NativeCheckoutService $nativeCheckoutService,
        ExternalCheckoutService $externalCheckoutService,
    ): JsonResponse {
        abort_if($liveShow->status === 'cancelled', 404);

        $validated = $request->validate([
            'registration_id' => ['nullable', 'integer'],
        ]);

        $liveShow->loadMissing(['featuredProducts', 'team']);
        $product = $liveShow->featuredProducts->firstWhere('id', $productId);
        abort_if($product === null, 404, 'This offer is not available for this webinar.');

        $pivot = $product->pivot;
        $ctaOverride = trim((string) ($pivot->cta_url ?? ''));
        if ($ctaOverride !== '' && filter_var($ctaOverride, FILTER_VALIDATE_URL)) {
            return response()->json([
                'checkout_url' => $ctaOverride,
                'mode' => 'custom',
            ]);
        }

        $defaultUrl = $offerService->defaultCheckoutUrl($liveShow->team, $product);
        if (str_starts_with($defaultUrl, 'http://') || str_starts_with($defaultUrl, 'https://')) {
            return response()->json([
                'checkout_url' => $defaultUrl,
                'mode' => 'external_link',
            ]);
        }

        $team = $liveShow->team ?? Team::query()->findOrFail($liveShow->team_id);
        $registrationId = (int) ($validated['registration_id'] ?? 0);
        $sessionKey = $registrationId > 0
            ? "webinar-{$liveShow->id}-{$registrationId}"
            : "webinar-{$liveShow->id}-guest";

        $cart = Cart::query()->firstOrCreate(
            [
                'team_id' => $team->id,
                'session_key' => $sessionKey,
                'status' => 'active',
            ],
            [
                'user_id' => null,
                'currency' => $product->currency ?: 'USD',
            ],
        );

        $cart->items()->where('product_id', '!=', $product->id)->delete();
        $this->upsertCartItem($cart, $product);

        $resolved = $checkoutResolver->resolve($team, 'hybrid', null);

        if ($resolved['mode'] === 'native') {
            $nativeProvider = $checkoutResolver->activeNativeProvider($team);

            if ($nativeProvider === null) {
                return response()->json([
                    'message' => 'Checkout is not configured for this store.',
                ], 422);
            }

            $order = $nativeCheckoutService->createPendingOrder($cart, [
                'source' => 'webinar_room',
                'live_show_id' => $liveShow->id,
            ], $nativeProvider);

            return response()->json([
                'mode' => 'native',
                'provider' => $nativeProvider,
                'checkout_url' => route('checkout.show', [
                    'order' => $order,
                    'token' => data_get($order->metadata, 'checkout_token'),
                ]),
                'order' => new OrderResource($order->load('items')),
            ], 201);
        }

        if ($resolved['provider'] === 'shopify') {
            $shopifyLines = $externalCheckoutService->shopifyCartLines($cart);

            if ($shopifyLines === []) {
                return response()->json([
                    'message' => 'This product cannot be checked out on Shopify.',
                ], 422);
            }
        }

        if ($resolved['provider'] === 'woocommerce') {
            $wooLines = $externalCheckoutService->wooCartLines($cart);

            if ($wooLines === []) {
                return response()->json([
                    'message' => 'This product cannot be checked out on WooCommerce.',
                ], 422);
            }
        }

        $session = $externalCheckoutService->createSession($cart, $team, (string) $resolved['provider']);

        return response()->json([
            'mode' => 'external',
            'provider' => $resolved['provider'],
            'checkout_url' => $session->checkout_url,
            'session_id' => $session->id,
        ], 201);
    }

    protected function upsertCartItem(Cart $cart, Product $product): void
    {
        $variantId = ProductVariant::query()
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->value('id');

        $unitPrice = $product->sale_price ?? $product->price ?? 0;

        $item = $cart->items()->firstOrNew([
            'product_id' => $product->id,
            'product_variant_id' => $variantId,
        ]);

        $item->quantity = 1;
        $item->unit_price = $unitPrice;
        $item->line_total = $unitPrice;
        $item->save();

        $cart->update(['total_amount' => $cart->items()->sum('line_total')]);
    }

    public function register(Request $request, LiveShow $liveShow): JsonResponse
    {
        abort_if($liveShow->status === 'cancelled', 404);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $registration = LiveShowRegistration::query()
            ->where('live_show_id', $liveShow->id)
            ->where('email', $validated['email'])
            ->first();

        if (! $registration) {
            $registration = LiveShowRegistration::query()->create([
                'live_show_id' => $liveShow->id,
                'full_name' => trim($validated['full_name']),
                'email' => mb_strtolower(trim($validated['email'])),
                'registered_at' => now(),
                'last_joined_at' => now(),
                'join_count' => 1,
            ]);
        } else {
            $registration->update([
                'full_name' => trim($validated['full_name']),
                'last_joined_at' => now(),
                'join_count' => (int) $registration->join_count + 1,
            ]);
        }

        return response()->json([
            'data' => [
                'registration_id' => $registration->id,
                'room_url' => url("/webinars/{$liveShow->id}/room?registration={$registration->id}"),
            ],
        ]);
    }

    public function messages(Request $request, LiveShow $liveShow): JsonResponse
    {
        abort_if($liveShow->status === 'cancelled', 404);

        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? 100);
        $query = LiveShowMessage::query()
            ->where('live_show_id', $liveShow->id)
            ->when(
                isset($validated['after_id']),
                fn ($builder) => $builder->where('id', '>', (int) $validated['after_id']),
            )
            ->orderBy('id')
            ->limit($limit);

        return response()->json([
            'data' => $query->get()->map(fn (LiveShowMessage $message): array => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'sender_name' => $message->sender_name,
                'live_show_registration_id' => $message->live_show_registration_id,
                'message' => $message->message,
                'is_pinned' => (bool) $message->is_pinned,
                'created_at' => $message->created_at,
            ]),
        ]);
    }

    public function sendMessage(
        Request $request,
        LiveShow $liveShow,
        WebinarAssistantService $assistantService,
    ): JsonResponse {
        abort_if($liveShow->status === 'cancelled', 404);

        $validated = $request->validate([
            'registration_id' => ['required', 'integer'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $registration = LiveShowRegistration::query()
            ->whereKey((int) $validated['registration_id'])
            ->where('live_show_id', $liveShow->id)
            ->first();

        if ($registration === null) {
            return response()->json([
                'message' => 'Registration not found. Open the registration page and join again before sending messages.',
            ], 422);
        }

        $message = LiveShowMessage::query()->create([
            'live_show_id' => $liveShow->id,
            'live_show_registration_id' => $registration->id,
            'sender_type' => 'attendee',
            'sender_name' => $registration->full_name,
            'message' => trim($validated['message']),
        ]);

        $created = [
            [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'sender_name' => $message->sender_name,
                'live_show_registration_id' => $message->live_show_registration_id,
                'message' => $message->message,
                'is_pinned' => (bool) $message->is_pinned,
                'created_at' => $message->created_at,
            ],
        ];

        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];
        if ((bool) data_get($settings, 'ai_assistant_enabled', false)) {
            $replyText = $assistantService->buildReply($liveShow, (string) $message->message);
            $aiMessage = LiveShowMessage::query()->create([
                'live_show_id' => $liveShow->id,
                'live_show_registration_id' => $registration->id,
                'sender_type' => 'ai',
                'sender_name' => 'AI Assistant',
                'message' => $replyText,
            ]);

            $created[] = [
                'id' => $aiMessage->id,
                'sender_type' => $aiMessage->sender_type,
                'sender_name' => $aiMessage->sender_name,
                'live_show_registration_id' => $aiMessage->live_show_registration_id,
                'message' => $aiMessage->message,
                'is_pinned' => (bool) $aiMessage->is_pinned,
                'created_at' => $aiMessage->created_at,
            ];
        }

        return response()->json(['data' => $created], 201);
    }

    public function recordWatchProgress(
        Request $request,
        LiveShow $liveShow,
        WebinarWatchProgressService $watchProgressService,
    ): JsonResponse {
        abort_if($liveShow->status === 'cancelled', 404);

        $validated = $request->validate([
            'registration_id' => ['required', 'integer'],
            'position_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'completed' => ['sometimes', 'boolean'],
        ]);

        $registration = LiveShowRegistration::query()
            ->whereKey((int) $validated['registration_id'])
            ->where('live_show_id', $liveShow->id)
            ->first();

        if ($registration === null) {
            return response()->json([
                'message' => 'Registration not found.',
            ], 422);
        }

        $registration = $watchProgressService->record(
            $liveShow,
            $registration,
            (int) $validated['position_ms'],
            (bool) ($validated['completed'] ?? false),
        );

        return response()->json([
            'data' => [
                'max_watch_ms' => (int) $registration->max_watch_ms,
                'reached_half_at' => $registration->reached_half_at,
                'watched_to_end_at' => $registration->watched_to_end_at,
            ],
        ]);
    }
}
