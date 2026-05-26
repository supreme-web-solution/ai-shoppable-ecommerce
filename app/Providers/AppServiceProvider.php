<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Configure API rate limiters for public and admin surfaces.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('admin-api', fn (Request $request): Limit => Limit::perMinute(300)->by(
            optional($request->user())->id ?: $request->ip(),
        ));

        RateLimiter::for('player-feed', fn (Request $request): Limit => Limit::perMinute(240)->by(
            (string) ($request->input('embed_slug') ?: $request->ip()),
        ));

        RateLimiter::for('player-engagement', fn (Request $request): Limit => Limit::perMinute(180)->by(
            (string) ($request->input('session_key') ?: $request->input('session_id') ?: $request->ip()),
        ));

        RateLimiter::for('analytics-ingest', fn (Request $request): Limit => Limit::perMinute(240)->by(
            (string) ($request->input('session_key') ?: $request->ip()),
        ));

        RateLimiter::for('integration-sync', fn (Request $request): Limit => Limit::perMinute(30)->by(
            optional($request->user())->id ?: $request->ip(),
        ));

        RateLimiter::for('integration-webhook', fn (Request $request): Limit => Limit::perMinute(180)->by(
            $request->ip(),
        ));
    }
}
