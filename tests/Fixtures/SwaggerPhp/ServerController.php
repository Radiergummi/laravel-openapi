<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

/**
 * Coolify-shaped controller: no operation annotations, but its typed return resolves to a model
 * carrying an authored `#[OA\Schema]`, so the harvester should attach that schema as the 200 body.
 */
class ServerController
{
    public function show(): AttributeServer
    {
        return new AttributeServer();
    }
}
