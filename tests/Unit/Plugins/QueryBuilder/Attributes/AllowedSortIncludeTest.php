<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder\Attributes;

use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;

it('stores the allowed sort fields', function (): void {
    $sort = new AllowedSort(['name', 'created_at']);

    expect($sort->fields)->toBe(['name', 'created_at']);
});

it('stores the allowed include relations', function (): void {
    $include = new AllowedInclude(['owner', 'tags']);

    expect($include->names)->toBe(['owner', 'tags']);
});
