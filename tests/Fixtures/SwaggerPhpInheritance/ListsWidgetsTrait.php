<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpInheritance;

use OpenApi\Annotations as OA;

/**
 * Test fixture — the `@OA\Get` operation is authored on this **trait** method; a controller that
 * `use`s the trait ({@see WidgetController}) provides the handler. Exercises the trait branch of
 * #187's ancestry walk (annotation indexed under the trait, route points at the using class).
 */
trait ListsWidgetsTrait
{
    /**
     * @OA\Get(
     *     path="/widgets",
     *     summary="Authored in a trait",
     *
     *     @OA\Response(
     *         response=200,
     *         description="OK",
     *
     *         @OA\JsonContent(ref="#/components/schemas/Widget"),
     *     ),
     * )
     */
    public function index(): array
    {
        return [];
    }
}
