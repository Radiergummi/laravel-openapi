<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp;

use Override;
use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\DocumentAnnotationMigration;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantComponentWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantOperationWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaReplaceableByAttribute;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\SchemaNameCollision;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

/**
 * Harvests hand-authored swagger-php annotations (`#[OA\Schema]`, `@OA\Schema`, operation-level
 * `@OA` annotations) and merges them into the generated document.
 *
 * Off by default; enable in `config/openapi.plugins`. PHPDoc annotations additionally require
 * `doctrine/annotations`; PHP 8 attributes work without it.
 */
final class SwaggerPhpPlugin implements Plugin
{
    #[Override]
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addStage(HarvestAuthoredAnnotationsStage::class);

        // Level-4 migration rules: flag hand-authored annotations the generator now covers.
        $registry->addRule(OaRedundantWithInference::class);
        $registry->addRule(OaRedundantOperationWithInference::class);
        $registry->addRule(OaRedundantComponentWithInference::class);
        $registry->addRule(OaReplaceableByAttribute::class);
        $registry->addRule(DocumentAnnotationMigration::class);

        // Stub: emitted by HarvestAuthoredAnnotationsStage; registration makes ID known to --list,
        // #[IgnoreLint], and severity overrides.
        $registry->addRule(SchemaNameCollision::class);
    }
}
