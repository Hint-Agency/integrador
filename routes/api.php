<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

Route::get('/status', fn () => response()->json(['status' => 'ok']));
Route::post('/treble/callback', function (Request $request) {
    Log::info('Treble callback received', $request->all());
    return response()->json(['status' => 'received']);
});
