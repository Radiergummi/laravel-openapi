<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Generator\Pipeline;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Extensions\OpenApiExtensions;

/**
 * Terminal stage. Applies user-registered document-level transformers
 * ({@see OpenApiExtensions::transformDocument}).
 *
 * Sits last in the pipeline so transformers see the fully assembled document.
 */
#[Scoped]
final readonly class TransformersStage implements SpecStage
{
    public function apply(OA\OpenApi $doc, GenerationContext $ctx): void
    {
        OpenApiExtensions::applyDocumentTransformers($doc);
    }
}
