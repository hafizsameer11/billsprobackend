<?php

use App\Http\Controllers\SwaggerDocsAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['web'])->group(function () {
    Route::get('/docs/login', [SwaggerDocsAuthController::class, 'showForm'])->name('swagger.docs.login');
    Route::post('/docs/email-otp', [SwaggerDocsAuthController::class, 'requestOtp'])->middleware('throttle:5,1');
    Route::post('/docs/verify-otp', [SwaggerDocsAuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
});
