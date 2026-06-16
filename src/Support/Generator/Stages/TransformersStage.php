<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Generator\GenerationContext;

/**
 * Terminal stage that applies user-registered document-level transformers
 * ({@see OpenApiExtensions::transformDocument}), positioned last so transformers
 * see the fully assembled document.
 *
 * @internal
 */
#[Scoped]
final readonly class TransformersStage implements SpecStage
{
    #[Override]
    public function apply(OA\OpenApi $document, GenerationContext $context): void
    {
        OpenApiExtensions::applyDocumentTransformers($document);
    }
}
