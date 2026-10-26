<?php

use Illuminate\Support\Facades\Route;

// API v1: thin controllers -> FormRequest -> Action -> Repository contract.
// Webhooks live under their own middleware group, not here.
Route::prefix('v1')->group(function (): void {
    Route::get('/ping', fn () => response()->json(['data' => ['status' => 'ok']]));
});
