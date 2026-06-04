<?php

use App\Http\Controllers\CheckoutPageController;
use App\Http\Controllers\EmbedPageController;
use App\Http\Controllers\ShopPageController;
use App\Http\Controllers\TeamInvitePageController;
use App\Http\Controllers\WebinarPageController;
use Illuminate\Support\Facades\Route;

Route::get('invites/{token}', [TeamInvitePageController::class, 'show'])->name('invites.show');

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('tutorial', [TutorialController::class, 'index'])->name('tutorial.index');
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('content', 'content/Index')->name('content.index');
    Route::inertia('content/create', 'content/Create')->name('content.create');
    Route::get('content/{videoId}/edit', function ($videoId) {
        return inertia('content/Edit', ['videoId' => (int) $videoId]);
    })->name('content.edit');
    Route::inertia('live-shows', 'live-shows/Index')->name('live-shows.page');
    Route::inertia('live-shows/chats', 'live-shows/chats/Index')->name('live-shows.chats.page');
    Route::get('live-shows/chats/webinars/{liveShow}', function ($liveShow) {
        return inertia('live-shows/Chats', [
            'source' => 'webinar',
            'webinarId' => (int) $liveShow,
            'lockContext' => true,
        ]);
    })->name('live-shows.chats.webinar');
    Route::get('live-shows/chats/live-videos/{video}', function ($video) {
        return inertia('live-shows/Chats', [
            'source' => 'live_video',
            'videoId' => (int) $video,
            'lockContext' => true,
        ]);
    })->name('live-shows.chats.live-video');
    Route::inertia('playlists', 'playlists/Index')->name('playlists.page');
    Route::inertia('products', 'products/Index')->name('products.page');
    Route::redirect('embeds', '/playlists')->name('embeds.page');
    Route::inertia('analytics', 'analytics/Index')->name('analytics.page');
    Route::inertia('teams', 'teams/Index')->name('teams.page');

    Route::middleware('platform.admin')->group(function (): void {
        Route::inertia('admin/users', 'admin/users/Index')->name('admin.users.page');
    });
});

Route::get('embed/{slug}', [EmbedPageController::class, 'show'])->name('embed.show');
Route::get('shop/{slug}', [ShopPageController::class, 'show'])->name('shop.show');
Route::get('checkout/{order}/{token}', [CheckoutPageController::class, 'show'])->name('checkout.show');
Route::get('webinars/{liveShow}/register', [WebinarPageController::class, 'register'])->name('webinars.register');
Route::get('webinars/{liveShow}/room', [WebinarPageController::class, 'room'])->name('webinars.room');

require __DIR__.'/settings.php';
