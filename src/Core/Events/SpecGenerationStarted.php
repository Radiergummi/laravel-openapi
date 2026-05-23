<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Events;

/**
 * Dispatched immediately before the document for one spec begins assembly.
 *
 * Has no guaranteed paired {@see SpecGenerationCompleted}: if assembly throws, this event
 * still fires but no completion event follows. Listeners that allocate resources (tracing
 * spans, profiler frames) should handle that via the framework's exception handlers, not
 * by relying on a matching end event.
 */
final readonly class SpecGenerationStarted
{
    public function __construct(
        public string $spec,
        public string $environment,
    ) {}
}
