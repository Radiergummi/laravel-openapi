<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Extractors;

use Radiergummi\OpenApi\Core\Attributes\ExceptionResponse;
use Radiergummi\OpenApi\Core\Enums\ComponentType;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Registry\ErrorResponseFactory;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use OpenApi\Annotations as OA;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;

use function array_key_exists;
use function base_path;
use function class_basename;
use function class_exists;
use function interface_exists;
use function ksort;
use function realpath;
use function sprintf;
use function str_starts_with;
use function strtoupper;

/**
 * Derives standard error responses from `@throws` annotations and route middleware.
 *
 * Each `@throws` FQCN is checked for a {@see ExceptionResponse} attribute; otherwise it falls back
 * to `config('openapi.exception_responses')`. Middleware mappings live
 * in `config('openapi.middleware_responses')`.
 *
 * This extractor only decides *which* status codes an operation exposes and their descriptions.
 * The error response *body* is contributed by registered {@see ErrorResponseFactory}
 * implementations, keeping the error-envelope shape (e.g. JSON:API) a plugin concern.
 *
 * Status codes are deduplicated (first wins). Explicit `#[OpenApi\Response]` attributes override
 * these via swagger-php's last-write-wins semantics.
 */
final readonly class StandardResponsesExtractor
{
    use DetectsAuthMiddleware;
    /**
     * @param list<ErrorResponseFactory>                             $errorResponseFactories
     * @param array<string, array{status: int, description: string}> $exceptionMap
     * @param array<string, array{status: int, description: string}> $middlewareMap
     */
    public function __construct(
        private ComponentSchemaRegistry $registry,
        private FindingsCollector $findings,
        private array $errorResponseFactories = [],
        private array $exceptionMap = [],
        private array $middlewareMap = [],
    ) {}

    /**
     * Maps HTTP status codes to stable `components.responses` component names.
     *
     * Derived from the description text in `config('openapi.exception_responses')`.
     */
    private const array STATUS_COMPONENT_NAMES = [
        400 => 'BadRequest',
        401 => 'Unauthorized',
        402 => 'PaymentRequired',
        403 => 'Forbidden',
        404 => 'NotFound',
        405 => 'MethodNotAllowed',
        409 => 'Conflict',
        422 => 'ValidationFailed',
        429 => 'TooManyRequests',
        500 => 'InternalServerError',
    ];

    /**
     * @return list<OA\Response>
     *
     * @throws ReflectionException
     */
    public function extract(ActionDescriptor $descriptor): array
    {
        /** @var array<int, array{description: string}> $byStatus */
        $byStatus = [];

        foreach ($descriptor->throws as $throw) {
            $entry = $this->resolveFromAttribute($throw)
                ?? $this->matchException($throw, $this->exceptionMap);

            if ($entry === null) {
                $this->findings->emit(
                    new Finding(
                        ruleId: 'throws.unmapped',
                        level: 2,
                        message: sprintf(
                            'Exception %s thrown from %s %s has no mapping',
                            $throw,
                            strtoupper($descriptor->route->methods()[0] ?? 'GET'),
                            $descriptor->route->uri(),
                        ),
                        location: new FindingLocation(
                            file: $descriptor->method?->getFileName() ?: null,
                            line: $descriptor->method?->getStartLine() ?: null,
                            routeName: $descriptor->route->getName(),
                            routeMethod: strtoupper($descriptor->route->methods()[0] ?? 'GET'),
                            routeUri: $descriptor->route->uri(),
                        ),
                        fixHint: $this->buildThrowsUnmappedHint($throw),
                        context: ['exception' => $throw],
                    ),
                );

                continue;
            }

            $status = (int) $entry['status'];

            if (!array_key_exists($status, $byStatus)) {
                $byStatus[$status] = ['description' => (string) $entry['description']];
            }
        }

        $middleware = $descriptor->route->gatherMiddleware();

        if (isset($this->middlewareMap['auth']) && $this->hasAuthMiddleware($middleware)) {
            $this->addOnce($byStatus, $this->middlewareMap['auth']);
        }

        if (isset($this->middlewareMap['scope']) && $this->hasScopeMiddleware($middleware)) {
            $this->addOnce($byStatus, $this->middlewareMap['scope']);
        }

        if (isset($this->middlewareMap['throttle']) && $this->hasThrottleMiddleware($middleware)) {
            $this->addOnce($byStatus, $this->middlewareMap['throttle']);
        }

        if ($byStatus === []) {
            return [];
        }

        $content = $this->errorResponseContent();

        ksort($byStatus);

        $responses = [];

        foreach ($byStatus as $status => $entry) {
            $componentName = self::STATUS_COMPONENT_NAMES[$status] ?? null;

            if ($componentName !== null) {
                // Register the full response definition at once; later operations reference it by
                // $ref, no per-operation schema inline. registerNamedResponse() is idempotent.
                $this->registry->registerNamedResponse(
                    $componentName,
                    $this->makeErrorResponse($componentName, $entry['description'], $content),
                );

                $responses[] = new OA\Response([
                    'response' => (string) $status,
                    'ref' => $this->registry->qualifyKey($componentName, ComponentType::Responses),
                ]);

                continue;
            }

            // Unknown status — inline (no component name mapped).
            $responses[] = $this->makeErrorResponse(
                (string) $status,
                $entry['description'],
                $content,
            );
        }

        return $responses;
    }

    /**
     * Resolves the error-response body from the first registered
     * {@see ErrorResponseFactory} that yields content. Returns null when no
     * factory is registered (or none produces content) — error responses are
     * then emitted description-only, with no body.
     *
     * @return null|list<OA\MediaType>
     */
    private function errorResponseContent(): ?array
    {
        foreach ($this->errorResponseFactories as $factory) {
            $content = $factory->errorResponseContent();

            if ($content !== null) {
                return $content;
            }
        }

        return null;
    }

    /**
     * Builds an error {@see OA\Response}, attaching the resolved body content
     * only when a factory provided it.
     *
     * @param null|list<OA\MediaType> $content
     */
    private function makeErrorResponse(string $response, string $description, ?array $content): OA\Response
    {
        $properties = [
            'response' => $response,
            'description' => $description,
        ];

        if ($content !== null) {
            $properties['content'] = $content;
        }

        return new OA\Response($properties);
    }

    /**
     * Build a context-aware fix hint for the throws.unmapped finding.
     *
     * For vendor/built-in exceptions (where we can't add attributes), the hint
     * directs users to the config map only. For app exceptions, it suggests
     * either approach.
     */
    private function buildThrowsUnmappedHint(string $exception): string
    {
        $basename = class_basename($exception);

        if (!class_exists($exception) && !interface_exists($exception)) {
            return sprintf(
                'Register "%s" in config/openapi.php (exception_responses map). '
                . 'The class could not be autoloaded — verify the @throws FQCN is correct.',
                $basename,
            );
        }

        if ($this->isVendorOrBuiltin($exception)) {
            return sprintf(
                'Register "%s" in config/openapi.php (exception_responses map), '
                . "e.g.: %s => ['status' => 500, 'description' => '...'].",
                $basename,
                "\\{$exception}::class",
            );
        }

        return sprintf(
            'Add #[ExceptionResponse(status: ..., description: ...)] to %s, '
            . 'or register it in config/openapi.php (exception_responses map).',
            $basename,
        );
    }

    /**
     * Determine whether an exception class is a vendor or PHP built-in class
     * (i.e., not part of the application source).
     */
    private function isVendorOrBuiltin(string $fqcn): bool
    {
        try {
            $file = new ReflectionClass($fqcn)->getFileName();
        } catch (ReflectionException) {
            return true;
        }

        // Built-in classes (e.g., RuntimeException) have no file.
        if ($file === false) {
            return true;
        }

        $vendorDir = realpath(base_path('vendor'));

        return $vendorDir !== false && str_starts_with(
            realpath($file) ?: $file,
            $vendorDir,
        );
    }

    /**
     * Returns null when the class cannot be loaded or carries no {@see ExceptionResponse} attribute
     *
     * `IS_INSTANCEOF` also matches the deprecated `Throws` subclass.
     *
     * @return null|array{status: int, description: string}
     */
    private function resolveFromAttribute(string $fqcn): ?array
    {
        if (!class_exists($fqcn)) {
            return null;
        }

        $attrs = new ReflectionClass($fqcn)->getAttributes(
            ExceptionResponse::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        if ($attrs === []) {
            return null;
        }

        $attr = $attrs[0]->newInstance();

        return ['status' => $attr->status, 'description' => $attr->description];
    }

    /**
     * @param array<string, array{status: int, description: string}> $map
     *
     * @return null|array{status: int, description: string}
     */
    private function matchException(string $name, array $map): ?array
    {
        if (isset($map[$name])) {
            return $map[$name];
        }

        $basename = class_basename($name);

        return $map[$basename] ?? null;
    }

    /**
     * @param array<int, array{description: string}>  $byStatus
     * @param array{status: int, description: string} $entry
     */
    private function addOnce(array &$byStatus, array $entry): void
    {
        $status = (int) $entry['status'];

        if (array_key_exists($status, $byStatus)) {
            return;
        }

        $byStatus[$status] = ['description' => (string) $entry['description']];
    }

    /**
     * @param list<string> $middleware
     */
    private function hasScopeMiddleware(array $middleware): bool
    {
        return array_any(
            $middleware,
            static fn(string $entry): bool
                => str_starts_with($entry, 'scope:')
                || str_starts_with($entry, 'scopes:'),
        );
    }

    /**
     * @param list<string> $middleware
     */
    private function hasThrottleMiddleware(array $middleware): bool
    {
        return array_any(
            $middleware,
            static fn(string $entry): bool => $entry === 'throttle' || str_starts_with($entry, 'throttle:'),
        );
    }

}
