<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\ZernioSocialController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('settings/appearance', '/settings/profile');

    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/integrations', 'settings/Integrations')->name('integrations.edit');

    $zernioPlatforms = 'instagram|facebook|tiktok|youtube|linkedin|twitter|x';

    Route::get('settings/integrations/zernio/{platform}/redirect', [ZernioSocialController::class, 'connectRedirect'])
        ->where('platform', $zernioPlatforms)
        ->name('integrations.zernio.redirect');

    Route::get('settings/integrations/zernio/{platform}/callback', [ZernioSocialController::class, 'callback'])
        ->where('platform', $zernioPlatforms)
        ->name('integrations.zernio.callback');
});
