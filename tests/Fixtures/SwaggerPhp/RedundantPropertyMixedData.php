<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

// `name` restates the inferred `string` type and is redundant; `role` carries a human-written
// description inference cannot derive, so only `name` is subsumed by inference.
#[OA\Schema(schema: 'RedundantPropertyMixed')]
final class RedundantPropertyMixedData extends Data
{
    public function __construct(
        #[OA\Property(property: 'name', type: 'string')]
        public string $name,
        #[OA\Property(property: 'role', type: 'string', description: 'The contact role.')]
        public string $role,
    ) {}
}
