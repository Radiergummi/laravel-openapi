<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

// Enforces the one-way dependency stated in CLAUDE.md:
// "src/Core/ must not depend on any plugin".
arch('Core must not depend on any plugin')
    ->expect('Radiergummi\OpenApi\Core')
    ->not->toUse('Radiergummi\OpenApi\Plugins');

arch('Support must not depend on Core')
    ->expect('Radiergummi\OpenApi\Support')
    ->not->toUse('Radiergummi\OpenApi\Core');
