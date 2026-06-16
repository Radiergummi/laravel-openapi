<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Generator;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

/**
 * One step in the OpenAPI document assembly pipeline.
 *
 * Stages run in a fixed order registered by {@see OpenApiRegistry::addStage()} and mutate
 * the shared {@see OA\OpenApi} document in place.
 */
interface SpecStage
{
    public function apply(OA\OpenApi $document, GenerationContext $context): void;
}
