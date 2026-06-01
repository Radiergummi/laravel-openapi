<?php

declare(strict_types=1);

// Enforces the one-way dependency stated in CLAUDE.md:
// "src/Core/ must not depend on any plugin".
arch('Core must not depend on any plugin')
    ->expect('Radiergummi\OpenApi\Core')
    ->not->toUse('Radiergummi\OpenApi\Plugins');

arch('Support must not depend on Core')
    ->expect('Radiergummi\OpenApi\Support')
    ->not->toUse('Radiergummi\OpenApi\Core');
