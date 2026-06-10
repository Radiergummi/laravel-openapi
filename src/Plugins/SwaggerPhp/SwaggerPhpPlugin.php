<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp;

use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantOperationWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\SchemaNameCollision;
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

        // `migration.*` cleanup rules (level 4): flag hand-authored annotations the generator now
        // reproduces on its own — class-level `#[OA\Schema]` and operation-level `@OA` annotations.
        // Surface with `openapi:lint --only 'migration.*'`.
        $registry->addRule(OaRedundantWithInference::class);
        $registry->addRule(OaRedundantOperationWithInference::class);

        // Registration stub (level 1): the `component.schema-name-collision` finding is emitted at
        // generation time by HarvestAuthoredAnnotationsStage; registering it here makes the ID known
        // to `--list`, `#[IgnoreLint]`, and severity overrides.
        $registry->addRule(SchemaNameCollision::class);
    }
}
