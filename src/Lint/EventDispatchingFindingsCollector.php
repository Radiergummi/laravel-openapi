<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Illuminate\Container\Attributes\Give;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Events\LintFindingEmitted;
use Radiergummi\OpenApi\OpenApiServiceProvider;
use Throwable;

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
        private LoggerInterface $logger,
    ) {}

    public function emit(Finding $finding): void
    {
        // A host-app listener throwing must not abort the lint run and discard the findings
        // collected so far — isolate it and log, then keep collecting.
        if ($this->events->hasListeners(LintFindingEmitted::class)) {
            try {
                $this->events->dispatch(new LintFindingEmitted($finding));
            } catch (Throwable $exception) {
                $this->logger->warning(
                    'A LintFindingEmitted listener threw; the finding was still collected.',
                    ['exception' => $exception],
                );
            }
        }

        $this->inner->emit($finding);
    }
}
