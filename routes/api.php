<?php

use Illuminate\Support\Facades\Route;
use Nexus\ImportExport\Http\Controllers\ImportController;

Route::prefix(config('bulk-imports.routes.prefix', 'api/imports'))
    ->middleware(config('bulk-imports.routes.middleware', ['api', 'auth']))
    ->group(function (): void {
        Route::get('templates/{type}', [ImportController::class, 'template']);
        Route::get('/', [ImportController::class, 'index']);
        Route::post('/', [ImportController::class, 'store']);
        Route::get('{import}', [ImportController::class, 'show']);
        Route::get('{import}/failures', [ImportController::class, 'failures']);
        Route::post('{import}/cancel', [ImportController::class, 'cancel']);
        Route::post('{import}/retry', [ImportController::class, 'retry']);
        Route::get('{import}/errors/download', [ImportController::class, 'downloadErrors']);
    });
