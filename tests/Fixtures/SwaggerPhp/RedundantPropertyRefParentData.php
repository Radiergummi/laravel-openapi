<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

// Its authored schema $refs RedundantPropertyNamedSchema by name; that surviving reference is what
// makes the named-schema class trip the per-class keep-guard.
#[OA\Schema(
    schema: 'RedundantPropertyRefParent',
    properties: [new OA\Property(property: 'child', ref: '#/components/schemas/RedundantPropertyNamedSchema')],
)]
final class RedundantPropertyRefParentData extends Data
{
    public function __construct(
        public RedundantPropertyNamedSchemaData $child,
    ) {}
}
