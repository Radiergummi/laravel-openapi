<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use Attribute;
use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class MixedGroupMarker {}

// A property whose attribute group mixes a non-OA attribute with *two* OA attributes, so the fixer
// must excise the whole OA run in one pass (there is no re-lint pass to catch leftovers).
#[OA\Schema(schema: 'RedundantMixed')]
final class RedundantMixedAttributeData extends Data
{
    public function __construct(
        #[MixedGroupMarker, OA\Property(property: 'name', type: 'string'), OA\Examples(example: 'sample')]
        public string $name,
    ) {}
}
