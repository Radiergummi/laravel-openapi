<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Registry;

use Exception;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

use function sprintf;

/**
 * Fault-isolation seam for resolver invocations: one failing route must not abort the run.
 * Catches {@see Exception} only; `Error`/`TypeError` (programming bugs) propagate normally.
 *
 * @internal
 */
final readonly class ResolverFaultBoundary
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
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
            $this->logger->warning(
                sprintf(
                    '%s failed for route %s: %s',
                    $resolver,
                    $action->route->uri(),
                    $exception->getMessage(),
                ),
            );

            return null;
        }
    }
}
