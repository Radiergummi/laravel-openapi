<?php

declare(strict_types=1);

use Examples\FormRequests\Http\BookingController;
use Examples\FormRequests\Http\FlightController;
use Illuminate\Support\Facades\Route;

Route::get('/flights', [FlightController::class, 'index']);
Route::get('/flights/{flight}', [FlightController::class, 'show']);
Route::post('/flights', [FlightController::class, 'store']);
Route::patch('/flights/{flight}', [FlightController::class, 'update']);
Route::delete('/flights/{flight}', [FlightController::class, 'destroy']);

Route::get('/flights/{flight}/bookings', [BookingController::class, 'index']);
Route::post('/flights/{flight}/bookings', [BookingController::class, 'store']);
Route::delete('/bookings/{booking}', [BookingController::class, 'destroy']);
