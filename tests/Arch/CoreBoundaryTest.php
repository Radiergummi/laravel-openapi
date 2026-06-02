<?php

declare(strict_types=1);

// Enforces the one-way dependencies stated in CLAUDE.md. Core moved under Plugins/, so these
// expectations target the real namespaces (they previously named the long-gone
// `Radiergummi\OpenApi\Core` and passed vacuously).

// "src/Core/ must not depend on any plugin": Core is the foundation the convention plugins build
// on, so it must never reach up into them.
arch('Core must not depend on a convention plugin')
    ->expect('Radiergummi\OpenApi\Plugins\Core')
    ->not->toUse([
        'Radiergummi\OpenApi\Plugins\ApiResources',
        'Radiergummi\OpenApi\Plugins\Fractal',
        'Radiergummi\OpenApi\Plugins\QueryBuilder',
        'Radiergummi\OpenApi\Plugins\SpatieData',
    ]);

// CLAUDE.md: "src/Support/ … must not depend on any plugin". Core being a plugin now, this
// generalises the former "Support must not depend on Core".
arch('Support must not depend on any plugin')
    ->expect('Radiergummi\OpenApi\Support')
    ->not->toUse('Radiergummi\OpenApi\Plugins');
