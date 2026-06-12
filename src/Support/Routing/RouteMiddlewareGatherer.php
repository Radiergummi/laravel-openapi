<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

use function array_merge;
use function array_values;
use function class_exists;
use function is_string;
use function spl_object_id;
use function sprintf;

/**
 * The single seam through which the generator reads a route's middleware list.
 *
 * The happy path is Laravel's own `Route::gatherMiddleware()`, which already resolves controller
 * middleware: the static `HasMiddleware::middleware()` form without instantiation, and classic
 * constructor `$this->middleware(...)` registrations by instantiating the controller through the
 * container. Both honour `only` / `except` scoping — for instantiable controllers nothing more is
 * needed.
 *
 * The instantiation path is the failure mode this class exists for: a controller whose
 * constructor cannot run in the generation context (unbound dependency, runtime side effects)
 * makes `gatherMiddleware()` throw, which previously crashed the whole run — and poisons the
 * route's internal middleware cache, so a retry silently returns `[]`. On a throw, this gatherer
 * logs a notice and falls back to the route-declared middleware merged with a bounded static scan
 * of the constructor body ({@see ConstructorMiddlewareScanner}), deduplicated, so security
 * schemes, implicit 401/403 responses, spec matching, and `openapi:why` all keep seeing one
 * uniform list. Results are cached per route, immune to the poisoned-cache footgun.
 *
 * @internal
 */
#[Scoped]
final class RouteMiddlewareGatherer
{
    /**
     * Gathered middleware keyed by route object id. Route objects live in the router for the
     * whole generation run, so ids are stable.
     *
     * @var array<int, array<int, mixed>>
     */
    private array $middlewareByRoute = [];

    /**
     * Controller classes for which degrade notices have been emitted, so a controller with many
     * routes logs once.
     *
     * @var array<class-string, true>
     */
    private array $notedControllers = [];

    public function __construct(
        private readonly ConstructorMiddlewareScanner $scanner,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Same shape as `Route::gatherMiddleware()`: middleware names plus any closure middleware
     * the route carries — callers already filter for strings.
     *
     * @return array<int, mixed>
     */
    public function middlewareFor(Route $route): array
    {
        $key = spl_object_id($route);

        if (isset($this->middlewareByRoute[$key])) {
            return $this->middlewareByRoute[$key];
        }

        try {
            return $this->middlewareByRoute[$key] = array_values($route->gatherMiddleware());
        } catch (Throwable $exception) {
            return $this->middlewareByRoute[$key] = $this->staticallyGatheredFallback($route, $exception);
        }
    }

    /**
     * Route-declared middleware plus the literal constructor registrations that apply to the
     * route's action method, deduplicated. Runs only when the runtime gather threw — the Tier-0
     * miss that licenses a body scan.
     *
     * @return array<int, mixed>
     */
    private function staticallyGatheredFallback(Route $route, Throwable $exception): array
    {
        /** @var array<int, mixed> $declared */
        $declared = array_values((array) $route->middleware());
        $controllerClass = $route->getControllerClass();

        if (!is_string($controllerClass) || !class_exists($controllerClass)) {
            return $declared;
        }

        $scan = $this->scanner->scan(new ReflectionClass($controllerClass));
        $this->noteDegradation($controllerClass, $exception, $scan);

        $actionMethod = $route->getActionMethod();

        // Invokable controllers report the class name as the action method.
        if ($actionMethod === $controllerClass) {
            $actionMethod = '__invoke';
        }

        /** @var array<int, mixed> $merged */
        $merged = Router::uniqueMiddleware(
            array_merge($declared, $scan->middlewareForAction($actionMethod)),
        );

        return $merged;
    }

    /**
     * @param class-string $controllerClass
     */
    private function noteDegradation(
        string $controllerClass,
        Throwable $exception,
        ConstructorMiddlewareScan $scan,
    ): void {
        if (isset($this->notedControllers[$controllerClass])) {
            return;
        }

        $this->notedControllers[$controllerClass] = true;

        $this->logger->notice(sprintf(
            'Controller %s could not be instantiated while gathering route middleware (%s); '
            . 'falling back to route-declared middleware plus a static scan of the constructor.',
            $controllerClass,
            $exception->getMessage(),
        ));

        if ($scan->unreadableCallDetected) {
            $this->logger->notice(sprintf(
                'A $this->middleware() registration in %s::__construct() has no statically '
                . 'readable name or scope; it is not documented. Annotate the affected actions '
                . 'with #[Security] or #[PublicEndpoint] to document them.',
                $controllerClass,
            ));
        }

        if ($scan->conditionalCallDetected) {
            $this->logger->notice(sprintf(
                'A $this->middleware() registration in %s::__construct() is conditionally '
                . 'applied; it is not documented, since documenting conditional middleware as '
                . 'unconditional would overstate the contract. Annotate the affected actions '
                . 'with #[Security] to document them.',
                $controllerClass,
            ));
        }
    }
}
