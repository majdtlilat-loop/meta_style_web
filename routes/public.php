<?php

declare(strict_types=1);

use App\Http\Controllers\CorporateLandingController;
use App\Http\Controllers\VerifyRegistrationEmailController;
use App\Kernel\Tenancy\PlatformHosts;
use App\Livewire\Auth\RegisterCenter;
use App\Livewire\Auth\RegistrationStatus;
use Illuminate\Support\Facades\Route;

$platformHosts = app(PlatformHosts::class);

Route::domain($platformHosts->corporateHost())->group(function (): void {
    Route::get('/', CorporateLandingController::class)->middleware('locale')->name('home');
    Route::get('/register', RegisterCenter::class)->middleware(['locale', 'throttle:registration'])->name('register');
    Route::get('/register/{uuid}/status', RegistrationStatus::class)->middleware(['locale', 'throttle:registration-status'])->name('registration.status');
    Route::get('/register/{uuid}/verify', VerifyRegistrationEmailController::class)->middleware(['signed', 'throttle:registration'])->name('registration.verify');
});
