<?php

declare(strict_types=1);

use Examples\ApiResources\Http\FlightController;
use Illuminate\Support\Facades\Route;

Route::get('/flights', [FlightController::class, 'index']);
Route::get('/flights/{flight}', [FlightController::class, 'show']);
