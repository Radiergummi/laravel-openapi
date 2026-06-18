<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

#[OA\Schema(schema: 'EnumProperty')]
final class EnumPropertyData extends Data
{
    public function __construct(
        // Carries an array `enum`, which AddAttribute cannot render as a scalar argument: the rule
        // logs and leaves it in place rather than rewriting it partially.
        #[OA\Property(property: 'status', type: 'string', enum: ['active', 'archived'])]
        public string $status,
    ) {}
}
