<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Registers the v1 core-auth route NAMES the FortifyContractTable keys on. The plugin matches by
// name, not action, so minimal closures suffice. Names mirror laravel/fortify's real action
// routes (the body-bearing POSTs are the `*.store` names; the bare `login`/`register` are GET view
// routes a headless API disables).
Route::post('/login', static fn() => null)->name('login.store');
Route::post('/logout', static fn() => null)->name('logout');
Route::post('/register', static fn() => null)->name('register.store');
Route::post('/forgot-password', static fn() => null)->name('password.email');
Route::post('/reset-password', static fn() => null)->name('password.update');
Route::post('/user/confirm-password', static fn() => null)->name('password.confirm.store');
Route::get('/user/confirmed-password-status', static fn() => null)->name('password.confirmation');
Route::put('/user/password', static fn() => null)->name('user-password.update');
Route::put('/user/profile-information', static fn() => null)->name('user-profile-information.update');
