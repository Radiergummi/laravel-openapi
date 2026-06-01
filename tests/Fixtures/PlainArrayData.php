<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Spatie\LaravelData\Data;

/** Data class with a plain (non-Data) array property — used by DataSyntheticPayloadBuilderTest. */
final class PlainArrayData extends Data
{
    public function __construct(
        /** @var list<string> */
        public array $tags,
    ) {}
}
