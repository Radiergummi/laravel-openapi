<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Illuminate\Container\Attributes\Give;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Events\Dispatcher;
use Radiergummi\OpenApi\Events\LintFindingEmitted;
use Radiergummi\OpenApi\OpenApiServiceProvider;

/**
 * Decorator that dispatches {@see LintFindingEmitted} for every emitted finding before
 * delegating to the wrapped collector.
 *
 * The bound {@see FindingsCollector} implementation; keeps the concrete collectors (logging,
 * in-memory) single-purpose and free of an event-dispatcher dependency. The dispatch is gated
 * on {@see Dispatcher::hasListeners()} so the per-finding cost is a single hash lookup when no
 * listener is registered.
 *
 * The {@see FindingsCollector} interface is mapped to this class via an explicit `scoped()`
 * alias in {@see OpenApiServiceProvider} (Testbench's `LoadConfiguration` skips the bootstrap
 * step `#[Bind]` depends on, so the attribute isn't viable here). The `#[Give]` annotation on
 * `$inner` pins the wrapped collector to {@see LoggingFindingsCollector}, which breaks the
 * would-be resolution cycle (resolving FindingsCollector → this class → FindingsCollector
 * inner). {@see LintRunner} still constructs this class manually with an
 * {@see ArrayFindingsCollector} inner during a lint run.
 */
#[Scoped]
final readonly class EventDispatchingFindingsCollector implements FindingsCollector
{
    public function __construct(
        #[Give(LoggingFindingsCollector::class)]
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
