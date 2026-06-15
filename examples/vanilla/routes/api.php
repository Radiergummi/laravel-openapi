<?php

declare(strict_types=1);

use Examples\Vanilla\Http\BookingController;
use Examples\Vanilla\Http\FindFlightController;
use Examples\Vanilla\Http\FlightController;
use Examples\Vanilla\Http\StatusController;
use Examples\Vanilla\Http\TypedFlightController;
use Illuminate\Support\Facades\Route;

Route::get('/status', [StatusController::class, 'show']);

Route::get('/flights', [FlightController::class, 'index']);
Route::get('/flights/{flight}', [FlightController::class, 'show']);
Route::post('/flights', [FlightController::class, 'store']);
Route::patch('/flights/{flight}', [FlightController::class, 'update']);
Route::delete('/flights/{flight}', [FlightController::class, 'destroy']);
Route::get('/flights/{flight}/manifest', [FlightController::class, 'manifest']);

Route::get('/flights/{flight}/bookings', [BookingController::class, 'index']);
Route::post('/flights/{flight}/bookings', [BookingController::class, 'store']);
Route::delete('/bookings/{booking}', [BookingController::class, 'destroy']);

Route::get('/typed/flights', [TypedFlightController::class, 'index']);
Route::get('/typed/flights/{flight}', [TypedFlightController::class, 'show']);

Route::get('/find/flights/{flight}', [FindFlightController::class, 'show']);
