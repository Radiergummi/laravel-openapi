<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use OpenApi\Annotations as OA;

/**
 * The result of an inference-only generation: a document produced with one or more stages excluded,
 * plus its source-class → component-schema index.
 *
 * Produced by {@see OpenApiGenerationOrchestrator::inferenceOnly()} so the lint layer can decide
 * whether a hand-authored annotation is redundant without a rule re-entering the pipeline. The
 * index is matched by source class, not by serialized name.
 *
 * @internal
 */
final readonly class InferenceOnlyGeneration
{
    /**
     * @param array<class-string, OA\Schema> $schemasByClass
     */
    public function __construct(
        public OA\OpenApi $document,
        public array $schemasByClass,
    ) {}
}
