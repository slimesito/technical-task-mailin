<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/blacklist')->name('home');

// Public tools: they talk to the JSON endpoints in routes/api.php.
Route::inertia('/blacklist', 'checks/blacklist')->name('checks.blacklist');
Route::inertia('/provider', 'checks/provider')->name('checks.provider');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
