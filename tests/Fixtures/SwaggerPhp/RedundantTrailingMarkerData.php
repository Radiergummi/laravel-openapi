<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

// `name` puts the OA attribute *before* a surviving non-OA one (a mixed run not at the group tail);
// `count` carries only a non-OA attribute (a group with no OA attributes to excise).
#[OA\Schema(schema: 'RedundantTrailingMarker')]
final class RedundantTrailingMarkerData extends Data
{
    public function __construct(
        #[OA\Property(property: 'name', type: 'string'), MixedGroupMarker]
        public string $name,
        #[MixedGroupMarker]
        public int $count,
    ) {}
}
