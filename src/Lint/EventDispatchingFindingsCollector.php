<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Illuminate\Container\Attributes\Give;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Events\Dispatcher;
use Override;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Events\LintFindingEmitted;
use Throwable;

/**
 * Decorator that dispatches {@see LintFindingEmitted} before delegating to the wrapped collector.
 *
 * Dispatch is gated on {@see Dispatcher::hasListeners()} to avoid overhead when unused. The
 * `#[Give]` attribute pins `$inner` to {@see LoggingFindingsCollector}, breaking the resolution
 * cycle caused by this class also implementing `FindingsCollector`.
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

    #[Override]
    public function emit(Finding $finding): void
    {
        // Isolate listener exceptions so a throwing listener cannot abort the lint run.
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
