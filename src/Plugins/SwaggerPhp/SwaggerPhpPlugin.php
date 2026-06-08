<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp;

use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

/**
 * Harvests the hand-authored swagger-php annotations a host app already wrote — `#[OA\Schema]` /
 * `@OA\Schema` definitions and operation-level `@OA` annotations — and merges the resulting schemas
 * and response bodies into the generated document.
 *
 * Off by default: enable it by listing the plugin in `config/openapi.plugins`. swagger-php is a
 * hard dependency of this package, so no guard is needed; harvesting `@OA` *PHPDoc* annotations
 * additionally requires `doctrine/annotations` (swagger-php parses `#[OA\*]` attributes without it).
 */
final class SwaggerPhpPlugin implements Plugin
{
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addStage(HarvestAuthoredAnnotationsStage::class);

        // A `migration.*` cleanup rule (level 4): flags hand-authored annotations the generator now
        // reproduces on its own. Surface with `openapi:lint --only 'migration.*'`.
        $registry->addRule(OaRedundantWithInference::class);
    }
}
