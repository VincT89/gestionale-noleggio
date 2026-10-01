<?php

use App\Http\Controllers\{AmdRentController, AmdRentEnquiryController};
use Illuminate\Support\Facades\Route;

Route::prefix('amd-rent')->name('amd-rent.')->group(function () {
    Route::get('/', [AmdRentController::class, 'index'])->name('index');
    Route::get('/impostazioni', [AmdRentController::class, 'settings'])->name('settings');
    Route::put('/impostazioni', [AmdRentController::class, 'saveSettings'])->name('settings.save');
    Route::put('/prenotazioni/{booking}/verifica', [\App\Http\Controllers\StripeBookingController::class, 'review'])->whereNumber('booking')->name('bookings.review');
    Route::get('/pratiche', [AmdRentEnquiryController::class, 'index'])->name('enquiries.index');
    Route::get('/pratiche/nuova', [AmdRentEnquiryController::class, 'create'])->name('enquiries.create');
    Route::post('/pratiche', [AmdRentEnquiryController::class, 'store'])->name('enquiries.store');
    Route::get('/pratiche/{enquiry}', [AmdRentEnquiryController::class, 'show'])->whereNumber('enquiry')->name('enquiries.show');
    Route::put('/pratiche/{enquiry}', [AmdRentEnquiryController::class, 'update'])->whereNumber('enquiry')->name('enquiries.update');
    Route::post('/pratiche/{enquiry}/riapri-consegna', [AmdRentEnquiryController::class, 'reopenDelivery'])->whereNumber('enquiry')->name('enquiries.reopen');
    Route::put('/pratiche/{enquiry}/commissioni', [AmdRentEnquiryController::class, 'commissions'])->whereNumber('enquiry')->name('enquiries.commissions');
    Route::post('/pratiche/{enquiry}/preventivi', [AmdRentEnquiryController::class, 'quote'])->whereNumber('enquiry')->name('enquiries.quote');
    Route::post('/pratiche/{enquiry}/documenti', [AmdRentEnquiryController::class, 'upload'])->whereNumber('enquiry')->name('enquiries.upload');
    Route::get('/pratiche/{enquiry}/documenti/{document}', [AmdRentEnquiryController::class, 'download'])->whereNumber(['enquiry', 'document'])->name('enquiries.download');
    Route::put('/pratiche/{enquiry}/documenti/{document}/visibilita', [AmdRentEnquiryController::class, 'documentVisibility'])->whereNumber(['enquiry', 'document'])->name('enquiries.document-visibility');
});
