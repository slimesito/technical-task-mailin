<?php

use App\Http\Controllers\Api\BulkCheckController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\SingleCheckController;
use Illuminate\Support\Facades\Route;

// {type} is the CheckType enum: "blacklist" or "provider". Anything else is a 404.
Route::prefix('checks/{type}')->group(function () {
    Route::post('single', [SingleCheckController::class, 'store'])->middleware('throttle:30,1');
    Route::post('bulk', [BulkCheckController::class, 'store'])->middleware('throttle:10,1');
});

Route::prefix('bulk-checks/{bulkCheck}')->group(function () {
    Route::get('/', [BulkCheckController::class, 'show']);
    Route::get('checks/{check}', [BulkCheckController::class, 'check'])->whereNumber('check');
    Route::get('export', ExportController::class);
});
