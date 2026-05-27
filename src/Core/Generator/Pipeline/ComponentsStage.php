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
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;

/**
 * Writes accumulated component schemas and responses from {@see ComponentSchemaRegistry}.
 *
 * Only allocates {@see OA\Components} when there is something to write; coexists with
 * {@see SecurityStage} which adds `securitySchemes` to the same components block.
 */
#[Scoped]
final readonly class ComponentsStage implements SpecStage
{
    public function __construct(
        private ComponentSchemaRegistry $schemaRegistry,
    ) {}

    public function apply(OA\OpenApi $doc, GenerationContext $ctx): void
    {
        $schemas = $this->schemaRegistry->all();
        $responses = $this->schemaRegistry->allResponses();

        if ($schemas === [] && $responses === []) {
            return;
        }

        $components = $doc->components instanceof OA\Components
            ? $doc->components
            : new OA\Components([]);

        if ($schemas !== []) {
            $components->schemas = $schemas;
        }

        if ($responses !== []) {
            $components->responses = $responses;
        }

        $doc->components = $components;
    }
}
