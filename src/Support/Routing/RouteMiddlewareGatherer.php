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
 * Reads a route's effective middleware list, with a fallback for non-instantiable controllers.
 *
 * Delegates to `Route::gatherMiddleware()`. When that throws (e.g., a controller that cannot be
 * instantiated in the generation context, which also poisons the route's cache), falls back to
 * route-declared middleware merged with a static constructor scan ({@see ConstructorMiddlewareScanner}).
 *
 * @internal
 */
#[Scoped]
final class RouteMiddlewareGatherer
{
    /** @var array<int, array<int, mixed>> */
    private array $middlewareByRoute = [];

    /** @var array<class-string, true> */
    private array $notedControllers = [];

    public function __construct(
        private readonly ConstructorMiddlewareScanner $scanner,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Same shape as `Route::gatherMiddleware()`.
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

        // Laravel reports the class name itself as the action method for invokable controllers.
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

        $this->logger->notice(
            sprintf(
                'Controller %s could not be instantiated while gathering route middleware (%s); '
                . 'falling back to route-declared middleware plus a static scan of the constructor.',
                $controllerClass,
                $exception->getMessage(),
            ),
        );

        if ($scan->unreadableCallDetected) {
            $this->logger->notice(
                sprintf(
                    'A $this->middleware() registration in %s::__construct() has no statically '
                    . 'readable name or scope; it is not documented. Annotate the affected actions '
                    . 'with #[Security] or #[PublicEndpoint] to document them.',
                    $controllerClass,
                ),
            );
        }

        if ($scan->conditionalCallDetected) {
            $this->logger->notice(
                sprintf(
                    'A $this->middleware() registration in %s::__construct() is conditionally '
                    . 'applied; it is not documented, since documenting conditional middleware as '
                    . 'unconditional would overstate the contract. Annotate the affected actions '
                    . 'with #[Security] to document them.',
                    $controllerClass,
                ),
            );
        }
    }
}
