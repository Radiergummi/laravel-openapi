<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Appliers;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\ResponseHeader as ResponseHeaderAttribute;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;

use function array_any;
use function array_filter;
use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Builds `#[ResponseHeader]` attributes onto responses and appends the headers Laravel always emits
 * for a route (a `Location` on a 201, the rate-limit pair under throttle middleware). Owns the
 * response-header `OA\Header` construction only.
 *
 * @internal
 */
final readonly class ResponseHeaderApplier
{
    public function __construct(
        private RouteMiddlewareGatherer $middlewareGatherer,
    ) {}

    /**
     * Method-level entries win on `(status, name)` collision; unmatched headers are dropped silently.
     *
     * @param list<OA\Response> $responses
     */
    public function applyAuthored(ActionDescriptor $descriptor, array $responses): void
    {
        /** @var array<string, array<string, ResponseHeaderAttribute>> $byStatus */
        $byStatus = [];

        foreach (
            [
                ...$descriptor->controllerAttributes(ResponseHeaderAttribute::class),
                ...$descriptor->actionAttributes(ResponseHeaderAttribute::class),
            ] as $attribute
        ) {
            $instance = $attribute->newInstance();
            $byStatus[(string) $instance->status][$instance->name] = $instance;
        }

        if ($byStatus === []) {
            return;
        }

        foreach ($responses as $response) {
            $status = (string) $response->response;

            if (!isset($byStatus[$status])) {
                continue;
            }

            $existing = is_array($response->headers) ? $response->headers : [];

            foreach ($byStatus[$status] as $headerAttribute) {
                $existing[] = $this->buildResponseHeader($headerAttribute);
            }

            $response->headers = $existing;
        }
    }

    /**
     * Appends headers Laravel always emits for a route, derived from signals the route carries:
     * `Location` on a 201 (created-resource redirect) and the rate-limit pair under throttle
     * middleware. Authored {@see ResponseHeaderAttribute} headers run first, so a name already
     * present on a response wins and the convention skips it.
     *
     * Rate-limit headers attach to the primary (success) response only: Laravel decorates a passing
     * response, not the 429, which carries a different header set.
     *
     * @param list<OA\Response> $responses
     */
    public function applyConventional(
        ActionDescriptor $descriptor,
        array $responses,
        OA\Response $primaryResponse,
    ): void {
        foreach ($responses as $response) {
            if ((string) $response->response === '201') {
                $this->appendDerivedHeader($response, 'Location', new OA\Schema([
                    'type' => 'string',
                    'format' => 'uri-reference',
                ]), 'URL of the created resource');
            }
        }

        if (!$this->hasThrottleMiddleware($descriptor)) {
            return;
        }

        $this->appendDerivedHeader($primaryResponse, 'X-RateLimit-Limit', new OA\Schema([
            'type' => 'integer',
        ]), 'The maximum number of requests allowed within the rate-limit window.');

        $this->appendDerivedHeader($primaryResponse, 'X-RateLimit-Remaining', new OA\Schema([
            'type' => 'integer',
        ]), 'The number of requests remaining in the current rate-limit window.');
    }

    private function buildResponseHeader(ResponseHeaderAttribute $header): OA\Header
    {
        $schemaProps = ['type' => $header->type];

        if ($header->format !== null) {
            $schemaProps['format'] = $header->format;
        }

        if ($header->example !== null) {
            $schemaProps['example'] = $header->example;
        }

        $props = [
            'header' => $header->name,
            'schema' => new OA\Schema($schemaProps),
        ];

        if ($header->description !== null) {
            $props['description'] = $header->description;
        }

        if ($header->required !== null) {
            $props['required'] = $header->required;
        }

        if ($header->deprecated !== null) {
            $props['deprecated'] = $header->deprecated;
        }

        return new OA\Header($props);
    }

    /** Appends a header to a response unless one of the same name is already present. */
    private function appendDerivedHeader(
        OA\Response $response,
        string $name,
        OA\Schema $schema,
        string $description,
    ): void {
        $existing = is_array($response->headers) ? $response->headers : [];

        foreach ($existing as $header) {
            if ($header instanceof OA\Header && $header->header === $name) {
                return;
            }
        }

        $existing[] = new OA\Header([
            'header' => $name,
            'schema' => $schema,
            'description' => $description,
        ]);

        $response->headers = $existing;
    }

    private function hasThrottleMiddleware(ActionDescriptor $descriptor): bool
    {
        $middleware = array_filter(
            $this->middlewareGatherer->middlewareFor($descriptor->route),
            is_string(...),
        );

        return array_any(
            $middleware,
            static fn(string $entry): bool => $entry === 'throttle' || str_starts_with($entry, 'throttle:'),
        );
    }
}
