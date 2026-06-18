<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

// Named `DivergentChild` (the author's name), but its authored schema carries a description
// inference cannot derive, so following the parent's $ref to this target fails subsumption: the
// parent stays flagged-NOT.
#[OA\Schema(schema: 'DivergentChild', description: 'A hand-written description inference omits.')]
final class DivergentRefChildData extends Data
{
    public function __construct(
        public string $label,
    ) {}
}
