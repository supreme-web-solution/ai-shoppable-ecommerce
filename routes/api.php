<?php

use App\Http\Controllers\Api\V1\Admin\AiContentController;
use App\Http\Controllers\Api\V1\Admin\ChatHubController;
use App\Http\Controllers\Api\V1\Admin\EmbedController;
use App\Http\Controllers\Api\V1\Admin\LeadController;
use App\Http\Controllers\Api\V1\Admin\LiveShowController;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use App\Http\Controllers\Api\V1\Admin\LiveVideoChatController;
use App\Http\Controllers\Api\V1\Admin\OverviewController;
use App\Http\Controllers\Api\V1\Admin\PlaylistController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\TeamController;
use App\Http\Controllers\Api\V1\Admin\TeamMemberController;
use App\Http\Controllers\Api\V1\Admin\UserManagementController;
use App\Http\Controllers\Api\V1\Admin\VideoController;
use App\Http\Controllers\Api\V1\Admin\VideoProductTagController;
use App\Http\Controllers\Api\V1\Admin\ZernioController;
use App\Http\Controllers\Api\V1\Analytics\DashboardController;
use App\Http\Controllers\Api\V1\Analytics\EventIngestionController;
use App\Http\Controllers\Api\V1\Integrations\NativePaymentWebhookController;
use App\Http\Controllers\Api\V1\Integrations\ShopifyController;
use App\Http\Controllers\Api\V1\Integrations\WooCommerceController;
use App\Http\Controllers\Api\V1\Player\CartController;
use App\Http\Controllers\Api\V1\Player\CheckoutController;
use App\Http\Controllers\Api\V1\Player\CheckoutOrderController;
use App\Http\Controllers\Api\V1\Player\EngagementController;
use App\Http\Controllers\Api\V1\Player\FeedController;
use App\Http\Controllers\Api\V1\Player\LinkPreviewController;
use App\Http\Controllers\Api\V1\Player\LiveShowController as PlayerLiveShowController;
use App\Http\Controllers\Api\V1\Player\NativePaymentController;
use App\Http\Controllers\Api\V1\Player\WebinarController as PlayerWebinarController;
use App\Http\Controllers\Api\V1\TeamInviteAcceptController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function (): void {
    Route::get('invites/{token}', [TeamInviteAcceptController::class, 'show']);
    Route::post('invites/{token}/accept', [TeamInviteAcceptController::class, 'accept'])
        ->middleware('auth:sanctum');

    Route::prefix('player')->middleware('throttle:player-engagement')->group(function (): void {
        Route::get('feed', [FeedController::class, 'index'])->middleware('throttle:player-feed');
        Route::get('live-show', [PlayerLiveShowController::class, 'current'])->middleware('throttle:player-feed');
        Route::get('webinars/{liveShow}', [PlayerWebinarController::class, 'show'])->middleware('throttle:player-feed');
        Route::get('webinars/{liveShow}/daily-token', [PlayerWebinarController::class, 'dailyViewerToken'])
            ->middleware('throttle:player-feed');
        Route::post('webinars/{liveShow}/register', [PlayerWebinarController::class, 'register']);
        Route::post('webinars/{liveShow}/watch-progress', [PlayerWebinarController::class, 'recordWatchProgress']);
        Route::get('webinars/{liveShow}/messages', [PlayerWebinarController::class, 'messages'])->middleware('throttle:player-feed');
        Route::post('webinars/{liveShow}/messages', [PlayerWebinarController::class, 'sendMessage']);
        Route::post('webinars/{liveShow}/offers/{productId}/checkout', [PlayerWebinarController::class, 'checkoutOffer']);
        Route::get('broadcast-config', [EngagementController::class, 'broadcastConfig'])->middleware('throttle:player-feed');
        Route::get('comments', [EngagementController::class, 'comments'])->middleware('throttle:player-feed');
        Route::get('link-preview', [LinkPreviewController::class, 'show'])->middleware('throttle:player-feed');
        Route::post('reactions', [EngagementController::class, 'react']);
        Route::post('comments', [EngagementController::class, 'comment']);
        Route::post('viewer-ping', [EngagementController::class, 'viewerPing']);
        Route::get('cart', [CartController::class, 'show']);
        Route::post('cart/items', [CartController::class, 'addItem']);
        Route::delete('cart/items/{itemId}', [CartController::class, 'removeItem']);
        Route::post('checkout', [CheckoutController::class, 'checkout']);
        Route::patch('checkout/orders/{order}/items/{item}', [CheckoutOrderController::class, 'updateItemQuantity']);
        Route::post('checkout/orders/{order}/start-payment', [NativePaymentController::class, 'start']);
        Route::post('checkout/orders/{order}/confirm-payment', [NativePaymentController::class, 'confirm']);
    });

    Route::prefix('analytics')->group(function (): void {
        Route::post('events', [EventIngestionController::class, 'store'])->middleware('throttle:analytics-ingest');
        Route::get('summary', [DashboardController::class, 'summary'])->middleware('auth:sanctum');
    });

    Route::prefix('integrations')->group(function (): void {
        Route::post('shopify/sync', [ShopifyController::class, 'sync'])
            ->middleware(['auth:sanctum', 'throttle:integration-sync']);
        Route::get('shopify/sync-status', [ShopifyController::class, 'syncStatus'])
            ->middleware(['auth:sanctum', 'throttle:admin-api']);
        Route::post('shopify/webhook', [ShopifyController::class, 'webhook'])
            ->middleware('throttle:integration-webhook');
        Route::post('woo/sync', [WooCommerceController::class, 'sync'])
            ->middleware(['auth:sanctum', 'throttle:integration-sync']);
        Route::get('woo/sync-status', [WooCommerceController::class, 'syncStatus'])
            ->middleware(['auth:sanctum', 'throttle:admin-api']);
        Route::post('woo/webhook', [WooCommerceController::class, 'webhook'])
            ->middleware('throttle:integration-webhook');
        Route::post('stripe/webhook', [NativePaymentWebhookController::class, 'stripe'])
            ->middleware('throttle:integration-webhook');
        Route::post('paypal/webhook', [NativePaymentWebhookController::class, 'paypal'])
            ->middleware('throttle:integration-webhook');
        Route::post('cloudinary/webhook', [ShopifyController::class, 'cloudinaryWebhook'])
            ->middleware('throttle:integration-webhook');
    });

    Route::middleware(['auth:sanctum', 'throttle:admin-api'])
        ->prefix('admin')
        ->name('admin.')
        ->group(function (): void {
            Route::get('overview', [OverviewController::class, 'show']);
            Route::post('videos/upload-params', [VideoController::class, 'uploadParams']);
            Route::post('videos/upload', [VideoController::class, 'upload']);
            Route::post('videos/{video}/prepare-upload', [VideoController::class, 'prepareUpload']);
            Route::post('videos/{video}/upload-chunk', [VideoController::class, 'uploadChunk']);
            Route::post('videos/{video}/retry-processing', [VideoController::class, 'retryProcessing']);
            Route::get('ai/heygen-options', [AiContentController::class, 'heygenOptions']);
            Route::get('ai/generations', [AiContentController::class, 'index']);
            Route::get('ai/generations/{generation}', [AiContentController::class, 'show']);
            Route::post('ai/scripts', [AiContentController::class, 'generateScript']);
            Route::post('ai/avatar-videos', [AiContentController::class, 'generateAvatarVideo']);
            Route::post('ai/multilingual-videos', [AiContentController::class, 'generateMultilingualVideos']);
            Route::post('teams/{team}/activate', [TeamController::class, 'activate']);
            Route::get('teams/{team}/members', [TeamMemberController::class, 'index']);
            Route::post('teams/{team}/invites', [TeamMemberController::class, 'invite']);
            Route::delete('teams/{team}/invites/{invite}', [TeamMemberController::class, 'destroyInvite']);
            Route::patch('teams/{team}/members/{user}', [TeamMemberController::class, 'updateMember']);
            Route::delete('teams/{team}/members/{user}', [TeamMemberController::class, 'destroyMember']);
            Route::apiResource('teams', TeamController::class);
            Route::apiResource('videos', VideoController::class);
            Route::get('videos/{video}/product-tags', [VideoProductTagController::class, 'index']);
            Route::post('videos/{video}/product-tags', [VideoProductTagController::class, 'store']);
            Route::post('videos/{video}/product-tags/sync', [VideoProductTagController::class, 'sync']);
            Route::put('videos/{video}/product-tags/{productTag}', [VideoProductTagController::class, 'update']);
            Route::delete('videos/{video}/product-tags/{productTag}', [VideoProductTagController::class, 'destroy']);
            Route::post('products/upload-image', [ProductController::class, 'uploadImage']);
            Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate']);
            Route::apiResource('products', ProductController::class);
            Route::apiResource('orders', OrderController::class)->only(['index', 'show']);
            Route::apiResource('leads', LeadController::class)->only(['index']);
            Route::apiResource('playlists', PlaylistController::class);
            Route::apiResource('embeds', EmbedController::class);
            Route::get('chats/summary', [ChatHubController::class, 'summary']);
            Route::apiResource('live-shows', LiveShowController::class);
            Route::get('live-shows/{liveShow}/attendees', [LiveShowController::class, 'attendees']);
            Route::post('live-shows/{liveShow}/attendees/notify', [LiveShowController::class, 'notifyAttendees']);
            Route::post('live-shows/{liveShow}/attendees/import', [LiveShowController::class, 'importAttendees']);
            Route::get('live-shows/{liveShow}/daily/token', [LiveShowController::class, 'dailyHostToken']);
            Route::post('live-shows/{liveShow}/offers/{product}/push', [LiveShowController::class, 'pushLiveOffer']);
            Route::post('live-shows/{liveShow}/offers/{product}/unpublish', [LiveShowController::class, 'unpublishLiveOffer']);
            Route::get('live-shows/{liveShow}/conversations', [LiveShowController::class, 'conversations']);
            Route::get('live-shows/{liveShow}/messages', [LiveShowController::class, 'messages']);
            Route::post('live-shows/{liveShow}/messages', [LiveShowController::class, 'postHostMessage']);
            Route::patch('live-shows/{liveShow}/messages/{message}', [LiveShowController::class, 'updateMessage']);
            Route::delete('live-shows/{liveShow}/messages/{message}', [LiveShowController::class, 'destroyMessage']);
            Route::get('live-video-chats', [LiveVideoChatController::class, 'index']);
            Route::get('live-video-chats/{video}/threads', [LiveVideoChatController::class, 'threads']);
            Route::get('live-video-chats/{video}/messages', [LiveVideoChatController::class, 'messages']);
            Route::post('live-video-chats/{video}/messages', [LiveVideoChatController::class, 'postMessage']);
            Route::patch('live-video-chats/{video}/comments/{comment}/hide', [LiveVideoChatController::class, 'hideComment']);
            Route::delete('live-video-chats/{video}/comments/{comment}', [LiveVideoChatController::class, 'deleteComment']);
            Route::post('live-video-chats/{video}/ban-session', [LiveVideoChatController::class, 'banSession']);
            Route::post('teams/{team}/tokens', [TeamController::class, 'issueToken']);

            Route::prefix('zernio')->group(function (): void {
                Route::get('status', [ZernioController::class, 'status']);
                Route::post('profile', [ZernioController::class, 'ensureProfile']);
                Route::get('connect', [ZernioController::class, 'connectUrl']);
                Route::get('accounts', [ZernioController::class, 'accounts']);
                Route::get('shop-link', [ZernioController::class, 'shopLink']);
                Route::post('publish', [ZernioController::class, 'publish']);
                Route::get('history', [ZernioController::class, 'history']);
            });

            Route::middleware('platform.admin')->group(function (): void {
                Route::get('platform/users', [UserManagementController::class, 'index']);
                Route::post('platform/users', [UserManagementController::class, 'store']);
                Route::patch('platform/users/{user}', [UserManagementController::class, 'update']);
                Route::delete('platform/users/{user}', [UserManagementController::class, 'destroy']);
            });
        });
});
