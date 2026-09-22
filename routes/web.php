<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\CalendarSelectionController;
use App\Http\Controllers\GoogleConnectionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('google', [GoogleConnectionController::class, 'show'])->name('google.connection');
    Route::get('google/connect', [GoogleConnectionController::class, 'create'])->name('google.connect');
    Route::get('google/callback', [GoogleConnectionController::class, 'store'])->name('google.callback');
    Route::delete('google', [GoogleConnectionController::class, 'destroy'])->name('google.disconnect');

    Route::put('calendars/selection', [CalendarSelectionController::class, 'update'])->name('calendar.update');

    Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::post('appointments', [AppointmentController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('appointments.store');
    Route::delete('appointments/{appointment}', [AppointmentController::class, 'destroy'])->name('appointments.destroy');
    Route::post('appointments/{appointment}/sync', [AppointmentController::class, 'sync'])
        ->middleware('throttle:30,1')
        ->name('appointments.sync');
});

require __DIR__.'/settings.php';
