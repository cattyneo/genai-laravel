<?php

use App\Http\Controllers\GenAIController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/genai', [GenAIController::class, 'test'])->name('genai.test');
