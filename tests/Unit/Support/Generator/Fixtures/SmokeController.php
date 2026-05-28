<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Generator\Fixtures;

use Radiergummi\OpenApi\Attributes\Response;

/**
 * Smoke-test controller for {@see OperationBuilder} unit tests.
 *
 * Exercises three shapes:
 *  - {@see plain()}     — no attributes, no docblock, baseline path.
 *  - {@see withResponse()} — explicit `#[Response]` overriding any auto-derived 2xx.
 *  - {@see documented()} — docblock summary/description carry through.
 */
final class SmokeController
{
    public function plain(): array
    {
        return [];
    }

    #[Response(status: 201, description: 'Created via attribute')]
    public function withResponse(): array
    {
        return [];
    }

    /**
     * Documented action.
     *
     * Has a longer description so the docblock parser has something to chew on.
     */
    public function documented(): array
    {
        return [];
    }
}
