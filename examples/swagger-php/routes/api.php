<?php

declare(strict_types=1);

use Examples\SwaggerPhp\Http\AircraftController;
use Examples\SwaggerPhp\Http\CrewController;
use Illuminate\Support\Facades\Route;

// Attribute-shaped: response body harvested from the #[OA\Schema] on the Aircraft model.
Route::get('/aircraft/{aircraft}', [AircraftController::class, 'show']);

// Docblock-shaped: response harvested from the controller's @OA\Get annotation.
Route::get('/crew/{id}', [CrewController::class, 'show']);
