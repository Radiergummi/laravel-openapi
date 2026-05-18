<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Registry\ErrorResponseFactory;

/**
 * Minimal {@see ErrorResponseFactory} fixture.
 *
 * Tests for {@see \Radiergummi\OpenApi\Core\Extractors\StandardResponsesExtractor}
 * need a concrete error-response factory to exercise the body-contribution
 * hook. The JSON:API plugin ships the production implementation; this fixture
 * supplies a self-contained equivalent for the package's own test suite.
 */
final readonly class FixtureErrorResponseFactory implements ErrorResponseFactory
{
    public function __construct(
        private ComponentSchemaRegistry $registry,
    ) {}

    public function errorResponseContent(): ?array
    {
        $this->registry->reserveKey(self::class);

        return [
            new OA\MediaType([
                'mediaType' => 'application/json',
                'schema'    => new OA\Schema(['type' => 'object']),
            ]),
        ];
    }
}
