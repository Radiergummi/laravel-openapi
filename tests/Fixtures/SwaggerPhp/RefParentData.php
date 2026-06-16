<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

// Its authored schema references RefChild by name; that surviving reference is what keeps RefChild's
// own annotation essential for the dangling-$ref guard.
#[OA\Schema(
    schema: 'RefParent',
    properties: [new OA\Property(property: 'child', ref: '#/components/schemas/RefChild')],
)]
final class RefParentData extends Data
{
    public function __construct(
        public RefChildData $child,
    ) {}
}
