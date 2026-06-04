<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Registry;

use Exception;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

use function sprintf;

/**
 * The single fault-isolation seam for resolver invocations. Every registered resolver — bundled
 * or plugin-supplied — runs through {@see isolate()}, so "one bad route must not abort the run"
 * is decided here once instead of being re-implemented per resolver.
 *
 * Catches {@see Exception} only: the library failures worth tolerating (reflection, type-info,
 * phpdoc parsing) are all `Exception` subclasses, as is any exception a plugin resolver throws.
 * `Error`/`TypeError` — programming bugs in our own or a plugin's resolver code — propagate as
 * stack traces rather than disappearing into a silent "no result".
 *
 * @internal
 */
final readonly class ResolverFaultBoundary
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Runs the resolver callable, returning its result. On a thrown {@see Exception} the failure
     * is logged with the resolver identity and route, and `null` is returned so the caller skips
     * this resolver and continues.
     *
     * @template T
     *
     * @param callable(): T $resolve
     *
     * @return null|T
     */
    public function isolate(string $resolver, ActionDescriptor $action, callable $resolve): mixed
    {
        try {
            return $resolve();
        } catch (Exception $exception) {
            $this->logger->warning(sprintf(
                '%s failed for route %s: %s',
                $resolver,
                $action->route->uri(),
                $exception->getMessage(),
            ));

            return null;
        }
    }
}
