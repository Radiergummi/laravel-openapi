<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Spatie\LaravelData\Data;

/** Data class whose only property is a nested Data class — used by DataSyntheticPayloadBuilderTest. */
final class NestedParentData extends Data
{
    public function __construct(
        public ScalarOnlyData $child,
    ) {}
}
