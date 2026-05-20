<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Examples\Combined\Http\BookingController;
use Examples\Combined\Http\FlightController;
use Examples\Combined\Http\InternalController;
use Illuminate\Support\Facades\Route;

Route::get('/flights', [FlightController::class, 'index']);
Route::get('/flights/{flight}', [FlightController::class, 'show']);
Route::post('/flights', [FlightController::class, 'store']);
Route::patch('/flights/{flight}', [FlightController::class, 'update']);
Route::delete('/flights/{flight}', [FlightController::class, 'destroy']);

Route::get('/flights/{flight}/bookings', [BookingController::class, 'index']);
Route::post('/flights/{flight}/bookings', [BookingController::class, 'store']);
Route::delete('/bookings/{booking}', [BookingController::class, 'destroy']);

Route::post('/bookings/{booking}/boarding-pass', [BookingController::class, 'uploadBoardingPass']);

// The InternalController is annotated `#[Hide]`, so this route is registered
// at runtime but excluded from the generated OpenAPI document.
Route::get('/internal/health', [InternalController::class, 'health']);
