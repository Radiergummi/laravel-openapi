<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Lint\Rules\FieldConflictingType;
use Spatie\LaravelData\Data;

/**
 * Fixture Data class for testing {@see FieldConflictingType}.
 */
final class ConflictingTypeFixtureData extends Data
{
    public function __construct(
        /** PHP type int → OpenAPI 'integer', but RequestField says 'string' → conflict */
        #[RequestField(type: 'string')]
        public int $conflicting,

        /** PHP type string → OpenAPI 'string', RequestField says 'string' → match */
        #[RequestField(type: 'string')]
        public string $matching,

        /** No explicit type in RequestField → no conflict */
        #[RequestField(description: 'No type set')]
        public int $noExplicitType,

        /** No RequestField attribute at all → no conflict */
        public string $noAttribute,
    ) {}
}
