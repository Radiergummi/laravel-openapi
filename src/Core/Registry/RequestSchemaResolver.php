<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Registry;

use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;

/**
 * Resolves the request-body schema for a controller action.
 *
 * Implementations inspect the action and either register a component schema
 * and return a {@see ResolvedSchema}, or return `null` to defer to the next
 * registered resolver. The first non-null result wins.
 */
interface RequestSchemaResolver
{
    public function resolveRequestSchema(ActionDescriptor $descriptor): ?ResolvedSchema;
}
