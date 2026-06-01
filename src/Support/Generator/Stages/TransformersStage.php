<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Generator\GenerationContext;

/**
 * Terminal stage. Applies user-registered document-level transformers
 * ({@see OpenApiExtensions::transformDocument}).
 *
 * Sits last in the pipeline so transformers see the fully assembled document.
 *
 * @internal
 */
#[Scoped]
final readonly class TransformersStage implements SpecStage
{
    public function apply(OA\OpenApi $document, GenerationContext $context): void
    {
        OpenApiExtensions::applyDocumentTransformers($document);
    }
}
