<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\Response;

final class ResponseRefAndSchemaFixture
{
    #[Response(status: 200, description: 'OK', ref: stdClass::class)]
    public function refOnly(): array
    {
        return [];
    }

    #[Response(status: 200, description: 'OK', schema: ['type' => 'string'])]
    public function schemaOnly(): array
    {
        return [];
    }

    #[Response(status: 200, description: 'OK')]
    public function neither(): array
    {
        return [];
    }

    #[Response(status: 422, description: 'Invalid', ref: stdClass::class, schema: ['type' => 'string'])]
    public function both(): array
    {
        return [];
    }

    #[Response(status: 200, description: 'OK', ref: stdClass::class, schema: null)]
    public function bothExplicitNullSchema(): array
    {
        return [];
    }
}
