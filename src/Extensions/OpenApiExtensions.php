<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Extensions;

use OpenApi\Annotations as OA;

/**
 * Registry for OpenAPI generation extension hooks.
 *
 * Three hook types are supported:
 *
 * **Operation transformer**: invoked per operation after assembly, before it is attached to
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
 * **Schema transformer**: invoked per component schema after extraction. Use this to add extra
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
 * **Document transformer**: invoked once on the assembled document at the end of the generation.
 * Use this to add top-level info, merge external spec fragments, set global extensions, etc.
 *
 * ```php
 * OpenApiExtensions::transformDocument(static function (OA\OpenApi $doc): void {
 *     $doc->x = ['api-version' => '2026-01'];
 * });
 * ```
 *
 * Vendor extensions use the `$x` array property on swagger-php annotations: `$doc->x = ['api-version' => …]`
 * produces `x-api-version: …`. Avoid dynamic property assignment; PHP 9 turns it into a fatal error.
 *
 * Register transformers in a service provider's `boot()` method exactly once. The arrays are never
 * deduplicated; registering inside a controller or middleware appends a duplicate on every request.
 * Under Octane the process stays alive across requests, so the arrays grow without bounds.
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
     * Register a callable invoked for every assembled operation.
     *
     * @param callable(OA\Operation, OperationContext): void $transformer
     */
    public static function transformOperation(callable $transformer): void
    {
        self::$operationTransformers[] = $transformer;
    }

    /**
     * Register a callable invoked for every component schema after extraction.
     *
     * Use this to inject constraints the generic extractor cannot derive (e.g., `pattern` for a
     * custom `HexColor` rule).
     *
     * @param callable(OA\Schema, SchemaContext): void $transformer
     */
    public static function transformSchema(callable $transformer): void
    {
        self::$schemaTransformers[] = $transformer;
    }

    /**
     * Register a callable invoked once on the fully assembled document, at the end of generation.
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
     * Removes all registered transformers. Useful in tests to isolate registrations between cases.
     */
    public static function flush(): void
    {
        self::$operationTransformers = [];
        self::$schemaTransformers = [];
        self::$documentTransformers = [];
    }
}
