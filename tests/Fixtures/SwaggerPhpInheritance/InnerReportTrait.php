<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpInheritance;

use OpenApi\Annotations as OA;

/**
 * Test fixture — the innermost trait of a trait-of-trait chain; carries the authored `@OA\Get`.
 * Reached via {@see OuterReportTrait} (recursively) and, in {@see TraitChainController}, also
 * directly — exercising the scanner's recursive trait collection and its re-visit dedup.
 */
trait InnerReportTrait
{
    /**
     * @OA\Get(
     *     path="/trait-chain",
     *     summary="Authored in an inner trait",
     *
     *     @OA\Response(response=200, description="OK"),
     * )
     */
    public function reportIndex(): array
    {
        return [];
    }
}
