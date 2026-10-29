<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugin\SubinfoCustomizer\Controllers\AssetController;

Route::prefix('api/v2/subinfo-customizer')->group(function (): void {
    // Public metadata and two fixed assets contain no users, settings or tokens.
    // The browser checks the administrator-only config API before mounting UI.
    Route::get('status', [AssetController::class, 'status']);
    Route::get('assets/{asset}', [AssetController::class, 'show'])->where('asset', 'script|style');
});
