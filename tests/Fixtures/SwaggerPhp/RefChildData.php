<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

// Inference reproduces this schema, but a sibling authored schema (RefParentData) still $refs it by
// name, so removing this annotation would dangle that reference — the rule must NOT flag it.
#[OA\Schema(schema: 'RefChild')]
final class RefChildData extends Data
{
    public function __construct(
        public string $label,
    ) {}
}
