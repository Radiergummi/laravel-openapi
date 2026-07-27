<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Attributes\ResponseField;
use Spatie\LaravelData\Data;

/**
 * Data class whose nullable property also carries a redundant `nullable: true` attribute, which
 * makes the nullability rule run twice over the same property schema.
 */
final class RedundantNullableAttributeData extends Data
{
    public function __construct(
        #[ResponseField(nullable: true)]
        public ?ScalarOnlyData $child = null,
    ) {}
}
