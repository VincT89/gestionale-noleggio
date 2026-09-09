<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use App\Observers\RentalDamageMediaObserver;
use App\Observers\MediaObserver;

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
        \Illuminate\Support\Facades\RateLimiter::for('public-bookings', fn (\Illuminate\Http\Request $request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($request->ip()));
        /**
         * Aggancia l'observer sul modello Media di Spatie.
         * In questo modo intercettiamo create/delete di media associati a RentalDamage.
         */
        SpatieMedia::observe(RentalDamageMediaObserver::class);
        SpatieMedia::observe(MediaObserver::class);
    }
}
