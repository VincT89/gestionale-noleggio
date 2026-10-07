<?php

use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\PublicCarSearchController;
use Illuminate\Support\Facades\Route;

$publicDomain = config('public_cars.domain');

// Register domain routes before the management application's root route.
Route::group(['domain' => $publicDomain, 'middleware' => 'public.customer:optional'], function () use ($publicDomain) {
    require __DIR__.'/public-customer.php';
    // Legal information stays available even when a browsing/action limit is reached.
    Route::prefix($publicDomain ? '' : 'cerca-auto')->group(function () {
        Route::get('/privacy', [\App\Http\Controllers\PublicLegalController::class, 'privacy'])->name('public-site.privacy');
        Route::get('/cookie', [\App\Http\Controllers\PublicLegalController::class, 'cookies'])->name('public-site.cookies');
    });
    Route::post('/amd-rent/stripe/webhook', [\App\Http\Controllers\StripeBookingController::class, 'webhook'])->name('amd-rent.stripe.webhook');
    Route::post('/prenotazione/{reference}/paga', [\App\Http\Controllers\StripeBookingController::class, 'resume'])->middleware(['signed', 'throttle:10,1,public-payment-'])->name('public-bookings.pay');
    Route::get('/richiesta/{reference}', [\App\Http\Controllers\PublicEnquiryController::class, 'show'])->middleware(['signed', 'throttle:60,1,public-summary-'])->name('public-enquiries.show');
    Route::post('/richiesta/{reference}/accetta', [\App\Http\Controllers\PublicEnquiryController::class, 'acceptDelivery'])->middleware(['signed', 'throttle:10,1,public-enquiry-actions-'])->name('public-enquiries.accept');
    Route::post('/richiesta/{reference}/riapri', [\App\Http\Controllers\PublicEnquiryController::class, 'reopenDelivery'])->middleware(['signed', 'throttle:10,1,public-enquiry-actions-'])->name('public-enquiries.reopen');
    Route::get('/prenotazione/{reference}', [PublicBookingController::class, 'confirmation'])
        ->middleware(['signed', 'throttle:60,1,public-summary-'])->name('public-bookings.confirmation');
    Route::get('/prenotazione/{reference}/pdf', [PublicBookingController::class, 'pdf'])
        ->middleware(['signed', 'throttle:30,1,booking-pdf-'])->name('public-bookings.pdf');

    // Browsing and actions have separate counters, including nested middleware.
    Route::prefix($publicDomain ? '' : 'cerca-auto')->middleware('throttle:60,1,public-browse-')->group(function () {
        Route::get('/lungo-termine', [\App\Http\Controllers\PublicEnquiryController::class, 'create'])->name('public-site.long-term');
        Route::post('/lungo-termine', [\App\Http\Controllers\PublicEnquiryController::class, 'store'])->middleware('throttle:5,1,public-long-term-')->name('public-site.long-term.store');
        Route::view('/come-funziona', 'public-cars.how-it-works')->name('public-site.how-it-works');
        Route::view('/assistenza', 'public-cars.support')->name('public-site.support');

        Route::name('public-cars.')->group(function () {
            Route::get('/', [PublicCarSearchController::class, 'index'])->name('index');
            Route::post('/ritiro-mappa/cerca', [\App\Http\Controllers\PublicPickupMapController::class, 'search'])->middleware('throttle:20,1,public-map-lookup-')->name('map.search');
            Route::post('/ritiro-mappa/indirizzo', [\App\Http\Controllers\PublicPickupMapController::class, 'address'])->middleware('throttle:20,1,public-map-lookup-')->name('map.address');
            Route::post('/ritiro-mappa/conferma', [\App\Http\Controllers\PublicPickupMapController::class, 'store'])->middleware('throttle:20,1,public-map-confirm-')->name('map.store');
            Route::post('/riconsegna-mappa/conferma', [\App\Http\Controllers\PublicPickupMapController::class, 'storeReturn'])->middleware('throttle:20,1,public-map-confirm-')->name('return-map.store');
            Route::get('/{offer}', [PublicCarSearchController::class, 'legacy'])->whereNumber('offer')->name('legacy.show');
            Route::get('/{offer}/foto', [PublicCarSearchController::class, 'legacy'])->whereNumber('offer')->name('legacy.photo');
            Route::get('/{offer}/prenota', [PublicCarSearchController::class, 'legacy'])->whereNumber('offer')->name('legacy.booking');
            Route::post('/{offer}/prenota', [PublicCarSearchController::class, 'expiredCheckout'])
                ->whereNumber('offer')->middleware('throttle:public-bookings')->name('legacy.store');
            Route::get('/auto/{pricelist}/prenota', [PublicBookingController::class, 'create'])
                ->whereNumber('pricelist')->name('booking.create');
            Route::post('/auto/{pricelist}/prenota', [PublicBookingController::class, 'store'])
                ->whereNumber('pricelist')->middleware('throttle:public-bookings')->name('booking.store');
            Route::get('/auto/{pricelist}/foto', [PublicCarSearchController::class, 'photo'])->whereNumber('pricelist')->name('photo');
            Route::get('/auto/{pricelist}', [PublicCarSearchController::class, 'show'])->whereNumber('pricelist')->name('show');
        });
    });
});

// Keep previously issued signed links usable when the public site moves to its own host.
if ($publicDomain) {
    Route::get('/prenotazione/{reference}', [PublicBookingController::class, 'legacyConfirmation'])
        ->middleware(['signed', 'throttle:60,1,public-summary-'])->name('public-bookings.legacy.confirmation');
    Route::get('/prenotazione/{reference}/pdf', [PublicBookingController::class, 'legacyPdf'])
        ->middleware(['signed', 'throttle:30,1,booking-pdf-'])->name('public-bookings.legacy.pdf');
}
