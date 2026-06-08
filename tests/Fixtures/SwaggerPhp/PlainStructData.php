<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use Spatie\LaravelData\Data;

// A plain Data class with no swagger-php annotation: inference produces a schema, but there is
// nothing authored to flag — the migration rule must skip it.
final class PlainStructData extends Data
{
    public function __construct(
        public string $name,
    ) {}
}
