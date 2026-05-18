<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Routing\Controller;

class RemoteMediaFixtureController extends Controller
{
    public function store(RemoteMediaFixtureRequest $request): array
    {
        return [];
    }
}
