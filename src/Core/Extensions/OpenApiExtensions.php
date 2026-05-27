<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Extensions;

use OpenApi\Annotations as OA;

/**
 * Registry for OpenAPI generation extension hooks.
 *
 * Three hook types are supported:
 *
 * **Operation transformer** — invoked per operation after assembly, before it is attached to
 * the document. Use this to add vendor extensions, mutate security requirements, change tags, etc.
 *
 * ```php
 * OpenApiExtensions::transformOperation(static function (OA\Operation $op, OperationContext $context): void {
 *     if (str_contains($context->routeUri(), 'webhooks/stripe')) {
 *         $op->tags = ['Stripe'];
 *     }
 * });
 * ```
 *
 * **Schema transformer** — invoked per component schema after extraction. Use this to add extra
 * constraints for custom Rule objects, mark properties as read-only, etc.
 *
 * ```php
 * OpenApiExtensions::transformSchema(static function (OA\Schema $schema, SchemaContext $context): void {
 *     if ($context->sourceClass === null) {
 *         return;
 *     }
 *     foreach ($schema->properties ?? [] as $property) {
 *         if ($property->property === 'password') {
 *             $property->format = 'password';
 *         }
 *     }
 * });
 * ```
 *
 * **Document transformer** — invoked once on the assembled document at the end of the generation.
 * Use this to add top-level info, merge external spec fragments, set global extensions, etc.
 *
 * ```php
 * OpenApiExtensions::transformDocument(static function (OA\OpenApi $doc): void {
 *     $doc->x = ['api-version' => '2026-01'];
 * });
 * ```
 *
 * Vendor extensions go through the declared `$x` array property on swagger-php annotations.
 * Keys are emitted prefixed with `x-`, so `$doc->x = ['api-version' => …]` produces
 * `x-api-version: …` in the output. Avoid `$doc->{'x-api-version'} = …` — PHP 8.2+ deprecates
 * dynamic property assignment on classes without `#[AllowDynamicProperties]`, and PHP 9 will
 * turn it into a fatal error.
 *
 * Register transformers in a service provider's `boot()` method or in `AppServiceProvider::boot()`.
 * All registered transformers survive for the lifetime of the process (Octane-safe, because
 * transformers are stateless callables, not per-request objects).
 *
 * WARNING — register each transformer exactly once, at boot time.
 * The three static arrays are never deduplicated. Calling a `transform*()` method outside a
 * service provider `boot()` — for example, inside a controller, middleware, or request lifecycle
 * hook — will append a duplicate on every request. Under Laravel Octane the process stays alive
 * across requests, so the arrays grow without bounds, and every transformer executes multiple times
 * per generation. If you need per-request behavior, pass a closure that reads request state
 * lazily rather than registering a new transformer on each request.
 */
final class OpenApiExtensions
{
    /** @var list<callable(OA\Operation, OperationContext): void> */
    private static array $operationTransformers = [];

    /** @var list<callable(OA\Schema, SchemaContext): void> */
    private static array $schemaTransformers = [];

    /** @var list<callable(OA\OpenApi): void> */
    private static array $documentTransformers = [];

    /**
     * Register a callable that will be invoked for every assembled operation.
     *
     * The callable receives the mutable {@see OA\Operation} and an {@see OperationContext}
     * carrying the source controller class, method name, HTTP verb, and route URI. Mutations to
     * `$operation` are reflected in the final document.
     *
     * @param callable(OA\Operation, OperationContext): void $transformer
     */
    public static function transformOperation(callable $transformer): void
    {
        self::$operationTransformers[] = $transformer;
    }

    /**
     * Register a callable that will be invoked for every component schema after extraction.
     *
     * The callable receives the mutable {@see OA\Schema} and a {@see SchemaContext} carrying
     * the component key and the source PHP class (null for hand-built named schemas).
     * Mutations to `$schema` — including its `properties` array — are reflected in the spec.
     *
     * This is the correct escape hatch for project-local rule objects: a transformer can inspect
     * `$context->sourceClass` and the property name on a nested `OA\Schema` to inject constraints that
     * the generic extractor cannot derive (e.g. for a custom `HexColor` rule, set `pattern`).
     *
     * @param callable(OA\Schema, SchemaContext): void $transformer
     */
    public static function transformSchema(callable $transformer): void
    {
        self::$schemaTransformers[] = $transformer;
    }

    /**
     * Register a callable that will be invoked once on the fully assembled OpenAPI document
     * at the very end of the generation, before it is returned to the caller.
     *
     * @param callable(OA\OpenApi): void $transformer
     */
    public static function transformDocument(callable $transformer): void
    {
        self::$documentTransformers[] = $transformer;
    }

    /**
     * @internal Called by {@see OpenApiGenerator}.
     */
    public static function applyOperationTransformers(OA\Operation $operation, OperationContext $context): void
    {
        foreach (self::$operationTransformers as $transformer) {
            $transformer($operation, $context);
        }
    }

    /**
     * @internal Called by {@see ComponentSchemaRegistry}.
     */
    public static function applySchemaTransformers(OA\Schema $schema, SchemaContext $context): void
    {
        foreach (self::$schemaTransformers as $transformer) {
            $transformer($schema, $context);
        }
    }

    /**
     * @internal Called by {@see OpenApiGenerator}.
     */
    public static function applyDocumentTransformers(OA\OpenApi $document): void
    {
        foreach (self::$documentTransformers as $transformer) {
            $transformer($document);
        }
    }

    /**
     * Removes all registered transformers.
     *
     * Useful in tests to isolate transformer registrations between cases.
     */
    public static function flush(): void
    {
        self::$operationTransformers = [];
        self::$schemaTransformers = [];
        self::$documentTransformers = [];
    }
}
