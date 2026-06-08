<?php

declare(strict_types=1);

namespace Examples\SwaggerPhp\Models;

use OpenApi\Annotations as OA;

/**
 * Invoice-Ninja-shaped model: the schema is hand-authored with an `@OA\Schema` PHPDoc annotation.
 * Harvesting PHPDoc annotations additionally requires `doctrine/annotations`.
 *
 * @OA\Schema(
 *     schema="Crew",
 *     description="A crew member assigned to flights.",
 *     required={"id", "name"},
 *
 *     @OA\Property(property="id", type="integer", description="Unique crew identifier.", example=7),
 *     @OA\Property(property="name", type="string", description="Full name.", example="Jane Roe"),
 *     @OA\Property(property="role", type="string", description="Assigned role.", example="Captain"),
 * )
 */
final class Crew {}
