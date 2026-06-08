<?php

declare(strict_types=1);

namespace Examples\SwaggerPhp\Http;

use OpenApi\Annotations as OA;

final class CrewController
{
    // The whole operation is hand-authored as an @OA\Get PHPDoc annotation, as an app already
    // documented for L5-Swagger would have it. The harvester merges the authored response (and the
    // Crew schema it references) onto the operation the library inferred from the route, and adopts
    // the annotation's summary/operationId/tags and the docblock prose below as the description.

    /**
     * Returns the crew member identified by the given id.
     *
     * @OA\Get(
     *     path="/api/crew/{id}",
     *     operationId="showCrew",
     *     summary="Show a crew member.",
     *     tags={"SwaggerPhp"},
     *
     *     @OA\Response(
     *         response=200,
     *         description="The crew member.",
     *
     *         @OA\JsonContent(ref="#/components/schemas/Crew"),
     *     ),
     * )
     */
    public function show(string $id): void {}
}
