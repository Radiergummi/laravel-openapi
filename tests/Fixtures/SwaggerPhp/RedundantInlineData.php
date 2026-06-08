<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

// The promoted parameter's attribute shares its line with the parameter, so the OA-only group does
// not occupy whole lines and must be excised byte-precisely rather than line-deleted.
#[OA\Schema(schema: 'RedundantInline')]
final class RedundantInlineData extends Data
{
    public function __construct(#[OA\Property(property: 'name', type: 'string')] public string $name) {}
}
