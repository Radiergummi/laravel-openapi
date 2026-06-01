<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;

/**
 * Writes `components.securitySchemes` from {@see OperationBuilder::buildSecuritySchemes()}.
 *
 * Always runs (even if no schemes are emitted) because the existing generator always sets the
 * `securitySchemes` key — preserving that means downstream consumers' fixture diffs stay clean.
 */
#[Scoped]
final readonly class SecurityStage implements SpecStage
{
    public function __construct(
        private OperationBuilder $operationBuilder,
    ) {}

    public function apply(OA\OpenApi $document, GenerationContext $context): void
    {
        $components = $document->components instanceof OA\Components
            ? $document->components
            : new OA\Components([]);

        $components->securitySchemes = $this->operationBuilder->buildSecuritySchemes();
        $document->components = $components;
    }
}
