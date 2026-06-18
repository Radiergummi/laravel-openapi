<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

// Its authored schema references the child by the author's divergent name (`DivergentChild` vs the
// convention's `DivergentRefChildData`). The oracle follows both refs, but the authored child target
// carries a description inference cannot reproduce, so the parent is NOT flagged redundant.
#[OA\Schema(
    schema: 'DivergentParent',
    properties: [new OA\Property(property: 'child', ref: '#/components/schemas/DivergentChild')],
)]
final class DivergentRefParentData extends Data
{
    public function __construct(
        public DivergentRefChildData $child,
    ) {}
}
