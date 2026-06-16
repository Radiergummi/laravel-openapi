<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Events;

/**
 * Dispatched before a spec document begins assembly.
 *
 * Has no guaranteed paired {@see SpecGenerationCompleted}: if assembly throws, this event fires
 * but no completion event follows. Listeners must not rely on a matching end event.
 */
final readonly class SpecGenerationStarted
{
    public function __construct(
        public string $spec,
        public string $environment,
    ) {}
}
