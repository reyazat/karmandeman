<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Telegram Mini App shell; Vue Router owns paths below /app.
Route::view('/app/{path?}', 'miniapp')->where('path', '.*');
