<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Registry\ResolvedSchema;

/**
 * Resolves the request-body schema for a controller action.
 *
 * Implementations inspect the action and either register a component schema and return a
 * {@see ResolvedSchema}, or return `null` to defer to the next registered resolver. The first
 * non-null result wins.
 */
interface RequestSchemaResolver
{
    public function resolveRequestSchema(ActionDescriptor $descriptor): ?ResolvedSchema;
}
