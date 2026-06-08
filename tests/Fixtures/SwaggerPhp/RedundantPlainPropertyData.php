<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

// No constructor, and the OA attribute sits on a declared property (not a promoted parameter), so
// the fixer must walk class property attribute groups, not just constructor params.
#[OA\Schema(schema: 'RedundantPlainProperty')]
final class RedundantPlainPropertyData extends Data
{
    #[OA\Property(property: 'name', type: 'string')]
    public string $name = '';
}
