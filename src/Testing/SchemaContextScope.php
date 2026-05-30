<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Testing;

use OpenApi\Context;
use OpenApi\Generator;

/**
 * Scope guard that pins swagger-php's global context to OAS 3.1.0 for the duration of a callable.
 *
 * Why this exists: {@see \OpenApi\Annotations\Schema::jsonSerialize()} consults the global
 * `Generator::$context` to decide whether to emit 3.0-compatible or 3.1-native schemas. Without
 * an explicit pin, the global defaults to {@see Generator::DEFAULT_VERSION} (3.0.0), which
 * silently rewrites 3.1-only keywords — `const: x` becomes `enum: [x]`, `examples: [...]`
 * becomes `example: ...`, etc.
 *
 * During real spec generation the {@see \Radiergummi\OpenApi\Support\Generator\SpecPipeline}
 * sets the root `openapi: 3.1.0` and swagger-php cascades that to descendants via
 * `$_context->root()`. Plugin *tests* that construct `OA\Schema` instances directly bypass that
 * path; this helper closes the gap.
 *
 * Usage:
 *
 * ```php
 * $json = SchemaContextScope::with(function (): string {
 *     $schema = new OA\Schema(['const' => 'value']);
 *     return json_encode($schema);
 * });
 * ```
 */
final class SchemaContextScope
{
    /**
     * @template T
     *
     * @param callable(): T $callable
     *
     * @return T
     */
    public static function with(callable $callable): mixed
    {
        $previous = Generator::$context;
        Generator::$context = new Context(['version' => '3.1.0'], $previous);

        try {
            return $callable();
        } finally {
            Generator::$context = $previous;
        }
    }
}
