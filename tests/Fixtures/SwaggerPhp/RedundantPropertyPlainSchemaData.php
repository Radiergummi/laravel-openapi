<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

// The no-cross-ref sibling of RedundantPropertyNamedSchemaData: identical redundant `label` member,
// but no other authored annotation $refs this schema by name, so the keep-guard does NOT fire and the
// member IS flagged. Pairing the two proves the guard test is not vacuous.
#[OA\Schema(schema: 'RedundantPropertyPlainSchema')]
final class RedundantPropertyPlainSchemaData extends Data
{
    public function __construct(
        #[OA\Property(property: 'label', type: 'string')]
        public string $label,
    ) {}
}
