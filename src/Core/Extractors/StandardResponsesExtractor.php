<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Extractors;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Attributes\ExceptionResponse;
use Radiergummi\OpenApi\Core\Enums\ComponentType;
use Radiergummi\OpenApi\Core\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Core\Errors\ErrorResponse;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use Throwable;

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
 * The error response *body* is contributed by registered {@see ErrorResponseResolver}
 * implementations, keeping the error-envelope shape (e.g. JSON:API) a plugin concern.
 *
 * Status codes are deduplicated (first wins). Explicit `#[OpenApi\Response]` attributes override
 * these via swagger-php's last-write-wins semantics.
 */
final readonly class StandardResponsesExtractor
{
    use DetectsAuthMiddleware;

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
     * @param list<ErrorResponseResolver>                                                                 $errorResponseResolvers
     * @param array<string, array{status: int, description: string}>                                      $exceptionMap
     * @param array<string, array{status: int, description: string, exception?: class-string<Throwable>}> $middlewareMap
     */
    public function __construct(
        private ComponentSchemaRegistry $registry,
        private FindingsCollector $findings,
        private array $errorResponseResolvers = [],
        private array $exceptionMap = [],
        private array $middlewareMap = [],
    ) {}

    /**
     * @return list<OA\Response>
     *
     * @throws ReflectionException
     */
    public function extract(ActionDescriptor $descriptor): array
    {
        /** @var array<int, array{description: string, exception?: class-string<Throwable>}> $byStatus */
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
                $stored = ['description' => (string) $entry['description']];

                if ((class_exists($throw) || interface_exists($throw))
                    && is_a($throw, Throwable::class, true)
                ) {
                    $stored['exception'] = $throw;
                }
                $byStatus[$status] = $stored;
            }
        }

        $middleware = array_values($descriptor->route->gatherMiddleware());

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

        ksort($byStatus);

        $responses = [];

        foreach ($byStatus as $status => $entry) {
            $exceptionClass = $entry['exception'] ?? null;

            $errorDescriptor = new ErrorDescriptor(
                status: $status,
                exceptionClass: $exceptionClass,
                description: (string) $entry['description'],
            );

            $body = $this->resolveBody($errorDescriptor);

            $componentName = self::STATUS_COMPONENT_NAMES[$status] ?? null;

            $responses[] = $this->buildResponse($errorDescriptor, $body, $componentName);
        }

        return $responses;
    }

    /**
     * Returns null when the class cannot be loaded or carries no {@see ExceptionResponse} attribute.
     *
     * `IS_INSTANCEOF` matches user-defined subclasses of {@see ExceptionResponse}.
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
            if (!class_exists($fqcn)) {
                return true;
            }

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
     * @param array<int, array{description: string, exception?: class-string<Throwable>}>  $byStatus
     * @param array{status: int, description: string, exception?: class-string<Throwable>} $entry
     */
    private function addOnce(array &$byStatus, array $entry): void
    {
        $status = (int) $entry['status'];

        if (array_key_exists($status, $byStatus)) {
            return;
        }

        $stored = ['description' => (string) $entry['description']];

        if (isset($entry['exception'])
            && (class_exists($entry['exception']) || interface_exists($entry['exception']))
            && is_a($entry['exception'], Throwable::class, true)
        ) {
            $stored['exception'] = $entry['exception'];
        }
        $byStatus[$status] = $stored;
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

    /**
     * Walks the resolver chain for one descriptor. First non-null wins. Returns null when
     * every resolver passes — the extractor then emits a bodyless response.
     *
     * The {@see ErrorResponseResolver} contract requires implementations to catch internally
     * and return null on failure, but the extractor defends against misbehaving resolvers
     * anyway: a throwing resolver emits a `errors.resolver-failed` finding and the chain
     * continues, matching the spec's promise that a single bad resolver does not abort the
     * full generation run.
     */
    private function resolveBody(ErrorDescriptor $descriptor): ?ErrorResponse
    {
        foreach ($this->errorResponseResolvers as $resolver) {
            try {
                $body = $resolver->resolveErrorResponse($descriptor);
            } catch (Throwable $e) {
                $this->findings->emit(new Finding(
                    ruleId: 'errors.resolver-failed',
                    level: 2,
                    message: sprintf(
                        'Error-response resolver %s threw %s while resolving status %d: %s',
                        $resolver::class,
                        $e::class,
                        $descriptor->status,
                        $e->getMessage(),
                    ),
                    context: [
                        'resolver'  => $resolver::class,
                        'status'    => $descriptor->status,
                        'exception' => $descriptor->exceptionClass,
                    ],
                ));

                continue;
            }

            if ($body !== null) {
                return $body;
            }
        }

        return null;
    }

    /**
     * Composes the resolver's body slice with the extractor-owned fields: response key,
     * default description, named-component registration.
     *
     * A named component is only used when the body is empty (description-only). When a
     * resolver produces content, headers, or links, the response is inlined per operation to
     * avoid first-write-wins collisions on the shared component — e.g. two operations at 422
     * with different resolver outputs (generic Error vs. ValidationError) would otherwise
     * silently share the first registration. The shared schemas referenced inside the content
     * (e.g. `$ref: '#/components/schemas/Error'`) are still reused; only the response wrapper
     * is inlined.
     */
    private function buildResponse(
        ErrorDescriptor $descriptor,
        ?ErrorResponse $body,
        ?string $componentName,
    ): OA\Response {
        $description = $descriptor->description;
        $content = $headers = $links = null;

        if ($body !== null) {
            // Only override the curated default description when the resolver supplied a
            // non-empty string; OpenAPI 3.1 requires response.description to be non-empty.
            if ($body->description !== null && $body->description !== '') {
                $description = $body->description;
            }

            if ($body->content !== []) {
                $content = $body->content;
            }

            if ($body->headers !== []) {
                $headers = $body->headers;
            }

            if ($body->links !== []) {
                $links = $body->links;
            }
        }

        $hasBody = $content !== null || $headers !== null || $links !== null;

        // Share via a named response component only when the body is empty
        // (description-only). Resolver-produced bodies may vary by descriptor —
        // e.g. validation vs generic at status 422 — so we inline them per operation
        // to avoid first-write-wins collisions on the shared component.
        if ($componentName !== null && !$hasBody) {
            $this->registry->registerNamedResponse(
                $componentName,
                new OA\Response([
                    'response'    => $componentName,
                    'description' => $description,
                ]),
            );

            return new OA\Response([
                'response' => (string) $descriptor->status,
                'ref'      => $this->registry->qualifyKey($componentName, ComponentType::Responses),
            ]);
        }

        $properties = [
            'response'    => (string) $descriptor->status,
            'description' => $description,
        ];

        if ($content !== null) {
            $properties['content'] = $content;
        }

        if ($headers !== null) {
            $properties['headers'] = $headers;
        }

        if ($links !== null) {
            $properties['links'] = $links;
        }

        return new OA\Response($properties);
    }

}
