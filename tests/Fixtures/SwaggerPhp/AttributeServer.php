<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;

/**
 * Coolify-shaped fixture: a model carrying a `#[OA\Schema]` PHP attribute.
 */
#[OA\Schema(schema: 'Server', required: ['id'])]
class AttributeServer
{
    #[OA\Property(property: 'id', type: 'integer')]
    public int $id = 0;

    #[OA\Property(property: 'name', type: 'string')]
    public string $name = '';
}
