<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Events;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Extensions\OpenApiExtensions;

/**
 * Dispatched once assembly (including document transformers) succeeds.
 *
 * Does not fire if assembly threw — see {@see SpecGenerationStarted} for the asymmetry. Mutate
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
