<?php

use App\Http\Controllers\GenAIController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/genai', [GenAIController::class, 'test'])->name('api.genai.test');