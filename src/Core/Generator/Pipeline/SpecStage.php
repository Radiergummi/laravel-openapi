<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Generator\Pipeline;

use OpenApi\Annotations as OA;

/**
 * One step in the OpenAPI document assembly pipeline.
 *
 * Stages run in a fixed order: core stages first, then plugin-registered stages (via
 * {@see \Radiergummi\OpenApi\Core\Registry\OpenApiRegistry::addStage()}), then the terminal
 * transformer stage. Each stage mutates the shared {@see OA\OpenApi} in place.
 */
interface SpecStage
{
    public function apply(OA\OpenApi $doc, GenerationContext $ctx): void;
}
