<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Annotations as OA;

/**
 * Authored operation whose response references a schema name that is not defined anywhere in the
 * scan. The harvester must skip the merge and log rather than emit a dangling `$ref`.
 */
class DanglingController
{
    /**
     * @OA\Get(
     *     path="/dangling",
     *
     *     @OA\Response(
     *         response=200,
     *         description="Dangling",
     *
     *         @OA\JsonContent(ref="#/components/schemas/DoesNotExist"),
     *     ),
     * )
     */
    public function index(): void {}
}
