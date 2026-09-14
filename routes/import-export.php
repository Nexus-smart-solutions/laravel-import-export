<?php

use Illuminate\Support\Facades\Route;
use Nexus\ImportExport\Http\Controllers\DataController;
use Nexus\ImportExport\Http\Controllers\ImportController;
use Nexus\ImportExport\Http\Middleware\AuthenticateDataOperation;
use Nexus\ImportExport\Http\Middleware\RejectClientIdentity;

Route::prefix(config('import-export.routes.prefix', 'api/data'))->middleware([...config('import-export.routes.middleware', ['api']), RejectClientIdentity::class])->group(function (): void {
    Route::post('resources/{resource}/guest-token', [DataController::class, 'guestToken'])->middleware('throttle:10,1');
    Route::middleware(AuthenticateDataOperation::class)->group(function (): void {
        Route::delete('guest-token', [DataController::class, 'revokeGuest']);
        Route::get('imports', [ImportController::class, 'index']);
        Route::get('exports', [DataController::class, 'indexExports']);
        Route::get('resources/{resource}', [DataController::class, 'metadata']);
        Route::get('resources/{resource}/template', [DataController::class, 'template']);
        Route::post('resources/{resource}/imports', [DataController::class, 'import']);
        Route::post('resources/{resource}/exports', [DataController::class, 'export']);
        Route::get('imports/{import}', [ImportController::class, 'show']);
        Route::get('imports/{import}/failures', [ImportController::class, 'failures']);
        Route::get('imports/{import}/failure-report', [ImportController::class, 'downloadErrors']);
        Route::post('imports/{import}/retry', [ImportController::class, 'retry']);
        Route::post('imports/{import}/cancel', [ImportController::class, 'cancel']);
        Route::get('exports/{export}', [DataController::class, 'showExport']);
        Route::get('exports/{export}/download', [DataController::class, 'download']);
        Route::post('exports/{export}/cancel', [DataController::class, 'cancelExport']);
        Route::post('exports/{export}/retry', [DataController::class, 'retryExport']);
    });
});
