<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

// The keep-guard positive control: inference reproduces the `label` property, but a sibling authored
// schema (RedundantPropertyRefParentData) still $refs this schema by name. The harvester would emit
// the authored schema under that name, so removing any member here could mutate that emitted
// component. The rule must skip the whole class.
#[OA\Schema(schema: 'RedundantPropertyNamedSchema')]
final class RedundantPropertyNamedSchemaData extends Data
{
    public function __construct(
        #[OA\Property(property: 'label', type: 'string')]
        public string $label,
    ) {}
}
