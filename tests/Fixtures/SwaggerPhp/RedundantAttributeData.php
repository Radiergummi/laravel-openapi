<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

// Line comment (not a docblock) so swagger-php does not lift it into the schema description.
#[OA\Schema(schema: 'RedundantAttribute')]
final class RedundantAttributeData extends Data
{
    public function __construct(
        #[OA\Property(property: 'name', type: 'string')]
        public string $name,
        #[OA\Property(property: 'count', type: 'integer')]
        public int $count,
    ) {}
}
