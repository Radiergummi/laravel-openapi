<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

/**
 * Resolves a class name to a JSON Schema `$ref` string of the form
 * `#/components/schemas/{key}`, registering the component schema if necessary.
 *
 * Implementations should return `null` when they do not handle the given class,
 * allowing the next resolver in the chain to be consulted (first non-null wins).
 */
interface RefSchemaResolver
{
    /**
     * Whether this resolver handles the given class. Must be side-effect-free — unlike
     * {@see resolveRef()}, it must not build or register any component schema. Callers that only
     * need to know whether a `$ref` would resolve (e.g. the `response.ref-unresolvable` lint rule)
     * use this to avoid the schema-building side effects of {@see resolveRef()}.
     *
     * @param class-string $class
     */
    public function canResolve(string $class): bool;

    /**
     * @param class-string $class
     *
     * @return null|string A `#/components/schemas/…` ref string, or null if this resolver
     *                     does not handle the given class.
     */
    public function resolveRef(string $class): ?string;
}
