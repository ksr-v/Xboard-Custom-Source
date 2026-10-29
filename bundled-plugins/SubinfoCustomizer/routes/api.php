<?php

use Illuminate\Support\Facades\Route;
use Plugin\SubinfoCustomizer\Controllers\SettingsController;
use Plugin\SubinfoCustomizer\Middleware\Enabled;

Route::prefix('api/v2/subinfo-customizer')->middleware(['admin', Enabled::class, 'throttle:60,1,subinfo-customizer:'])->group(function (): void {
    Route::get('config', [SettingsController::class, 'show']);
    Route::post('config', [SettingsController::class, 'save']);
    Route::get('defaults', [SettingsController::class, 'defaults']);
});
