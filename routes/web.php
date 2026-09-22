<?php

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

    Route::get('calendars', [CalendarSelectionController::class, 'edit'])->name('calendar.edit');
    Route::put('calendars/selection', [CalendarSelectionController::class, 'update'])->name('calendar.update');
});

require __DIR__.'/settings.php';
