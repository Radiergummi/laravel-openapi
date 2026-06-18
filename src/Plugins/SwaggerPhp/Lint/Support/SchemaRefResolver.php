<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use OpenApi\Annotations as OA;

/**
 * Resolves a component `$ref` name to its target schema, on each of the two sides the redundancy
 * oracle compares.
 *
 * The authored and inferred documents name the same class differently (convention uses
 * `class_basename`, the author may pin an explicit `#[OA\Schema(schema: '…')]`), so a `$ref` string
 * carries a side-specific name: the narrower (authored) ref must resolve against the authored
 * components, the broader (inferred) ref against the inference-only control. Keeping the two lookups
 * distinct avoids conflating the namespaces. An unknown name yields null, which the comparator treats
 * conservatively (not subsumed).
 *
 * @internal
 */
interface SchemaRefResolver
{
    public function resolveAuthored(string $name): ?OA\Schema;

    public function resolveInferred(string $name): ?OA\Schema;
}
