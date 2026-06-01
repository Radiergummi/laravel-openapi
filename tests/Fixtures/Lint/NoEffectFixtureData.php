<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Lint\Rules\FieldNoEffect;
use Spatie\LaravelData\Data;

/**
 * Fixture Data class for testing {@see FieldNoEffect}.
 */
final class NoEffectFixtureData extends Data
{
    public function __construct(
        /** All defaults → dead attribute → finding */
        #[RequestField]
        public string $noEffect,

        /** Has a description → effective → no finding */
        #[RequestField(description: 'Has a description')]
        public string $hasDescription,

        /** Has writeOnly → effective → no finding */
        #[RequestField(writeOnly: true)]
        public string $hasWriteOnly,

        /** No RequestField at all → no finding */
        public string $noAttribute,
    ) {}
}
