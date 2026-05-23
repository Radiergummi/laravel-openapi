<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

use Illuminate\Contracts\Events\Dispatcher;
use Radiergummi\OpenApi\Core\Events\LintFindingEmitted;

/**
 * Decorator that dispatches {@see LintFindingEmitted} for every emitted finding before
 * delegating to the wrapped collector.
 *
 * The bound {@see FindingsCollector} implementation; keeps the concrete collectors
 * (logging, in-memory) single-purpose and free of an event-dispatcher dependency. The
 * dispatch is gated on {@see Dispatcher::hasListeners()} so the per-finding cost is a
 * single hash lookup when no listener is registered.
 */
final readonly class EventDispatchingFindingsCollector implements FindingsCollector
{
    public function __construct(
        private FindingsCollector $inner,
        private Dispatcher $events,
    ) {}

    public function emit(Finding $finding): void
    {
        if ($this->events->hasListeners(LintFindingEmitted::class)) {
            $this->events->dispatch(new LintFindingEmitted($finding));
        }

        $this->inner->emit($finding);
    }
}
