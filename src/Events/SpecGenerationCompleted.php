<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Events;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;

/**
 * Dispatched once assembly (including document transformers) succeeds.
 *
 * Does not fire if assembly threw; see {@see SpecGenerationStarted} for the asymmetry. Mutate
 * via {@see OpenApiExtensions::transformDocument()}, not from this listener.
 */
final readonly class SpecGenerationCompleted
{
    public function __construct(
        public string $spec,
        public string $environment,
        public OA\OpenApi $document,
        public float $durationMs,
    ) {}
}
