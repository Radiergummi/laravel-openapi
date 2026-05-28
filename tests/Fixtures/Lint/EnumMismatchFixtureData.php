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
use Radiergummi\OpenApi\Lint\Rules\FieldEnumMismatch;
use Spatie\LaravelData\Data;

/**
 * Fixture Data class for testing {@see FieldEnumMismatch}.
 */
final class EnumMismatchFixtureData extends Data
{
    public function __construct(
        /** Missing 'pending' compared to FixtureStatusEnum cases → finding */
        #[RequestField(enum: ['active', 'inactive'])]
        public FixtureStatusEnum $mismatched,

        /** Exact match with FixtureStatusEnum cases → no finding */
        #[RequestField(enum: ['active', 'inactive', 'pending'])]
        public FixtureStatusEnum $matching,

        /** No enum specified → no finding */
        #[RequestField(description: 'No enum')]
        public FixtureStatusEnum $noEnum,

        /** Enum on a non-BackedEnum property → no finding */
        #[RequestField(enum: ['a', 'b'])]
        public string $nonEnumType,
    ) {}
}
