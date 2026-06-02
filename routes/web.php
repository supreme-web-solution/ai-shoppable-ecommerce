<?php

use App\Http\Controllers\CheckoutPageController;
use App\Http\Controllers\EmbedPageController;
use App\Http\Controllers\TutorialController;
use App\Http\Controllers\WebinarPageController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('tutorial', [TutorialController::class, 'index'])->name('tutorial.index');
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('content', 'content/Index')->name('content.index');
    Route::inertia('content/create', 'content/Create')->name('content.create');
    Route::get('content/{videoId}/edit', function ($videoId) {
        return inertia('content/Edit', ['videoId' => (int) $videoId]);
    })->name('content.edit');
    Route::inertia('live-shows', 'live-shows/Index')->name('live-shows.index');
    Route::inertia('live-shows/chats', 'live-shows/chats/Index')->name('live-shows.chats');
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
    Route::inertia('playlists', 'playlists/Index')->name('playlists.index');
    Route::inertia('products', 'products/Index')->name('products.index');
    Route::redirect('embeds', '/playlists')->name('embeds.index');
    Route::inertia('analytics', 'analytics/Index')->name('analytics.index');
});

Route::get('embed/{slug}', [EmbedPageController::class, 'show'])->name('embed.show');
Route::get('checkout/{order}/{token}', [CheckoutPageController::class, 'show'])->name('checkout.show');
Route::get('webinars/{liveShow}/register', [WebinarPageController::class, 'register'])->name('webinars.register');
Route::get('webinars/{liveShow}/room', [WebinarPageController::class, 'room'])->name('webinars.room');

require __DIR__.'/settings.php';
