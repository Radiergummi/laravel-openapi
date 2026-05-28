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
use Radiergummi\OpenApi\Attributes\QueryParam;

/**
 * Test fixture — exercises class-level vs method-level `#[QueryParam]`.
 *
 * Routes are wired up in {@see QueryParamClassLevelTest}'s `beforeEach`.
 */
#[QueryParam('tenant', description: 'Active tenant slug', type: 'string')]
#[QueryParam('locale', type: 'string', default: 'en')]
class QueryParamClassFixtureController extends Controller
{
    public function inheritedAction(): array
    {
        return [];
    }

    /** Method-level override of `locale` plus a new `page` parameter. */
    #[QueryParam('locale', type: 'string', default: 'de')]
    #[QueryParam('page', type: 'integer', default: 1, minimum: 1)]
    public function overrideAction(): array
    {
        return [];
    }
}
