<?php

declare(strict_types=1);

namespace Examples\SwaggerPhp\Models;

use OpenApi\Attributes as OA;

/**
 * Coolify-shaped model: the schema is hand-authored with `#[OA\Schema]` PHP attributes. The
 * harvester picks it up and attaches it as the response body of any action that returns an
 * Aircraft — no inference, exactly the author's schema.
 */
#[OA\Schema(schema: 'Aircraft', description: 'A registered aircraft.', required: ['id', 'registration'])]
final class Aircraft
{
    #[OA\Property(property: 'id', type: 'integer', description: 'Unique aircraft identifier.', example: 42)]
    public int $id = 0;

    #[OA\Property(property: 'registration', type: 'string', description: 'Tail registration number.', example: 'D-AIMA')]
    public string $registration = '';

    #[OA\Property(property: 'seats', type: 'integer', description: 'Total passenger seats.', example: 853)]
    public int $seats = 0;
}
