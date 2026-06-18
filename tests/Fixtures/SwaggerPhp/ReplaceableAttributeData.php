<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

#[OA\Schema(schema: 'ReplaceableAttribute')]
final class ReplaceableAttributeData extends Data
{
    public function __construct(
        #[OA\Property(property: 'name', type: 'string', format: 'email', description: 'The contact email.')]
        public string $name,
    ) {}
}
