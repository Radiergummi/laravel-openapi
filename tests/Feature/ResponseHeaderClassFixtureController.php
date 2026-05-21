<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Core\Attributes\Response;
use Radiergummi\OpenApi\Core\Attributes\ResponseHeader;

/**
 * Test fixture — exercises class-level vs method-level `#[ResponseHeader]`.
 *
 * Routes are wired up in {@see ResponseHeaderClassLevelTest}'s `beforeEach`.
 */
#[ResponseHeader(name: 'X-Request-Id', description: 'Per-request correlation id')]
class ResponseHeaderClassFixtureController extends Controller
{
    #[Response(status: 200, description: 'OK')]
    public function inheritedHeaderAction(): array
    {
        return [];
    }

    /** Method-level override of the same (status, name) pair. */
    #[Response(status: 200, description: 'OK')]
    #[ResponseHeader(name: 'X-Request-Id', description: 'Overridden by the method')]
    #[ResponseHeader(name: 'X-RateLimit-Remaining', type: 'integer', format: 'int32')]
    public function overrideAction(): array
    {
        return [];
    }
}
