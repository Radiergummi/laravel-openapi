<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Generator;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

/**
 * One step in the OpenAPI document assembly pipeline.
 *
 * Stages run in a fixed order: core stages first, then plugin-registered stages (via
 * {@see OpenApiRegistry::addStage()}), then the terminal transformer stage. Each stage mutates the
 * shared {@see OA\OpenApi} in place.
 */
interface SpecStage
{
    public function apply(OA\OpenApi $document, GenerationContext $context): void;
}
