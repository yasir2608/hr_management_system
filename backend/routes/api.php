<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [LoginController::class, 'store']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [SessionController::class, 'show']);
    Route::post('/auth/logout', [LoginController::class, 'destroy']);

    Route::middleware('company')->group(function (): void {
        //
    });
});
