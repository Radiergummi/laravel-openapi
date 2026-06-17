<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Support\Str;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

use function array_filter;
use function config;
use function count;
use function preg_replace;
use function strtolower;

/**
 * Derives operationIds the way the generator emits them, and sanitises an existing operationId to
 * the codegen-safe character set.
 *
 * Shared by the paths stage (which stamps the inferred operationId onto each operation) and the
 * lint Fix layer (which synthesises or repairs `#[Operation(operationId: …)]`), so a fixer produces
 * exactly what inference would have emitted. Stateless and plugin-agnostic.
 *
 * @internal
 */
final readonly class OperationIdDeriver
{
    /**
     * Derives an operation ID per `openapi.operation_id_strategy`: `route-name` (default) uses the
     * named route, falling back to `{method}_{path}`; `method-path` always uses `{method}_{path}`.
     */
    public function derive(ActionDescriptor $descriptor, HttpMethod $method): string
    {
        if (config('openapi.operation_id_strategy') === 'method-path') {
            return $this->methodPathOperationId($descriptor, $method);
        }

        return $this->routeNameOperationId($descriptor, $method);
    }

    /** Replaces invalid characters with `_` and strips leading non-letters; preserves dots. */
    public function sanitise(string $operationId): string
    {
        $sanitised = preg_replace('/[^A-Za-z0-9._-]+/', '_', $operationId) ?? $operationId;

        return preg_replace('/^[^A-Za-z]+/', '', $sanitised) ?? $sanitised;
    }

    private function methodPathOperationId(ActionDescriptor $descriptor, HttpMethod $method): string
    {
        $sanitised = preg_replace('/[^a-zA-Z0-9]+/', '_', $descriptor->route->uri())
            ?? $descriptor->route->uri();

        return strtolower($method->value) . '_' . $sanitised;
    }

    /**
     * Named route: `{name}.{method}` for multi-method routes, `{name}` otherwise.
     * Generated/unnamed (`generated::*` or null): `{method}_{sanitised_path}`.
     */
    private function routeNameOperationId(ActionDescriptor $descriptor, HttpMethod $method): string
    {
        $name = $descriptor->route->getName();

        if ($name !== null && !Str::startsWith($name, 'generated::')) {
            $methods = array_filter(
                $descriptor->route->methods(),
                static fn(string $method): bool => HttpMethod::fromString($method) !== HttpMethod::Head,
            );

            $operationId = count($methods) > 1
                ? $name . '.' . strtolower($method->value)
                : $name;

            return $this->sanitise($operationId);
        }

        return $this->methodPathOperationId($descriptor, $method);
    }
}
