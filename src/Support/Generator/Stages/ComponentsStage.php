<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

/**
 * Writes accumulated component schemas and responses from {@see ComponentSchemaRegistry}.
 *
 * Only allocates {@see OA\Components} when there is something to write; coexists with
 * {@see SecurityStage} which adds `securitySchemes` to the same components block.
 *
 * @internal
 */
#[Scoped]
final readonly class ComponentsStage implements SpecStage
{
    public function __construct(
        private ComponentSchemaRegistry $schemaRegistry,
    ) {}

    #[Override]
    public function apply(OA\OpenApi $document, GenerationContext $context): void
    {
        $schemas = $this->schemaRegistry->all();
        $responses = $this->schemaRegistry->allResponses();

        if ($schemas === [] && $responses === []) {
            return;
        }

        $components = $document->components instanceof OA\Components
            ? $document->components
            : new OA\Components([]);

        if ($schemas !== []) {
            $components->schemas = $schemas;
        }

        if ($responses !== []) {
            $components->responses = $responses;
        }

        $document->components = $components;
    }
}
