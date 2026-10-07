<?php

use App\Http\Controllers\{PublicCustomerAuthController, PublicCustomerController};
use Illuminate\Support\Facades\Route;

Route::prefix('area-cliente')->name('public-account.')->group(function () {
    Route::middleware('public.customer:guest')->group(function () {
        Route::get('/accedi', [PublicCustomerAuthController::class, 'loginForm'])->name('login');
        Route::post('/accedi', [PublicCustomerAuthController::class, 'login'])->middleware('throttle:20,1,public-account-login-')->name('login.store');
        Route::get('/registrati', [PublicCustomerAuthController::class, 'registerForm'])->name('register');
        Route::post('/registrati', [PublicCustomerAuthController::class, 'register'])->middleware('throttle:5,1,public-account-register-')->name('register.store');
        Route::get('/password-dimenticata', [PublicCustomerAuthController::class, 'forgotForm'])->name('password.request');
        Route::post('/password-dimenticata', [PublicCustomerAuthController::class, 'forgot'])->middleware('throttle:5,1,public-account-recovery-')->name('password.email');
        Route::get('/nuova-password/{token}', [PublicCustomerAuthController::class, 'resetForm'])->name('password.reset');
        Route::post('/nuova-password', [PublicCustomerAuthController::class, 'reset'])->middleware('throttle:5,1,public-account-reset-')->name('password.update');
    });
    Route::middleware('public.customer:auth')->group(function () {
        Route::get('/verifica-email', [PublicCustomerAuthController::class, 'verificationNotice'])->name('verification.notice');
        Route::post('/verifica-email', [PublicCustomerAuthController::class, 'verificationSend'])->middleware('throttle:3,1,public-account-verification-send-')->name('verification.send');
        Route::get('/verifica-email/{id}/{hash}', [PublicCustomerAuthController::class, 'verify'])->middleware(['signed', 'throttle:10,1,public-account-verification-'])->name('verification.verify');
        Route::post('/esci', [PublicCustomerAuthController::class, 'logout'])->name('logout');
        Route::get('/profilo', [PublicCustomerAuthController::class, 'profile'])->name('profile');
        Route::put('/profilo', [PublicCustomerAuthController::class, 'updateProfile'])->middleware('throttle:10,1,public-account-profile-')->name('profile.update');
        Route::put('/password', [PublicCustomerAuthController::class, 'updatePassword'])->middleware('throttle:5,1,public-account-password-')->name('password.change');
    });
    Route::middleware('public.customer')->group(function () {
        Route::get('/', [PublicCustomerController::class, 'bookings'])->name('bookings');
        Route::get('/prenotazioni/{reference}', [PublicCustomerController::class, 'booking'])->name('booking');
        Route::get('/prenotazioni/{reference}/pdf', [PublicCustomerController::class, 'pdf'])->middleware('throttle:30,1,public-account-pdf-')->name('booking.pdf');
        Route::get('/richieste', [PublicCustomerController::class, 'enquiries'])->name('enquiries');
        Route::get('/richieste/{reference}', [PublicCustomerController::class, 'enquiry'])->name('enquiry');
        Route::post('/richieste/{reference}/documenti', [PublicCustomerController::class, 'upload'])->middleware('throttle:10,1,public-account-upload-')->name('documents.upload');
        Route::get('/richieste/{reference}/documenti/{document}', [PublicCustomerController::class, 'download'])->whereNumber('document')->name('documents.download');
    });
});
