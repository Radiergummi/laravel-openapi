<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Testing;

use OpenApi\Context;
use OpenApi\Generator;
use Radiergummi\OpenApi\Support\Generator\SpecPipeline;

/**
 * Scope guard that pins swagger-php's global context to OAS 3.1.0 for the duration of a callable.
 *
 * Without this pin, `Generator::$context` defaults to 3.0.0, which silently rewrites 3.1-only
 * keywords (`const: x` becomes `enum: [x]`, etc.). Real generation sets 3.1.0 via `SpecPipeline`;
 * plugin tests that construct `OA\Schema` instances directly bypass that path.
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
