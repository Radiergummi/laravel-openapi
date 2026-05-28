<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Pagination;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Enums\PaginatorKind;

/**
 * Builds the flat OpenAPI schema Laravel serializes a bare paginator to via its
 * `toArray()` method. This is the raw-paginator shape; the `{data, links,
 * meta}` resource envelope is a separate shape produced only when a
 * `ResourceCollection` wraps a paginator, and is handled by the ApiResources
 * plugin.
 *
 * Nullable paginator fields (cursors, page URLs) are modelled as non-nullable for this first cut; OpenAPI 3.1
 * nullability would be layered in via NullableSchema later.
 */
final class PaginatorSchemaFactory
{
    /**
     * Builds the response-body schema for one paginator kind, wrapping the
     * supplied per-item schema in the `data` array.
     */
    public function envelope(PaginatorKind $kind, OA\Items $items): OA\Schema
    {
        $properties = [
            $this->prop('data', ['type' => 'array', 'items' => $items]),
            $this->prop('per_page', ['type' => 'integer']),
            $this->prop('path', ['type' => 'string']),
        ];

        if ($kind === PaginatorKind::Cursor) {
            $properties[] = $this->prop('next_cursor', ['type' => 'string']);
            $properties[] = $this->prop('prev_cursor', ['type' => 'string']);
            $properties[] = $this->prop('next_page_url', ['type' => 'string']);
            $properties[] = $this->prop('prev_page_url', ['type' => 'string']);

            return new OA\Schema(['type' => 'object', 'properties' => $properties]);
        }

        // LengthAware and Simple share these.
        $properties[] = $this->prop('current_page', ['type' => 'integer']);
        $properties[] = $this->prop('from', ['type' => 'integer']);
        $properties[] = $this->prop('to', ['type' => 'integer']);
        $properties[] = $this->prop('first_page_url', ['type' => 'string']);
        $properties[] = $this->prop('next_page_url', ['type' => 'string']);
        $properties[] = $this->prop('prev_page_url', ['type' => 'string']);

        if ($kind === PaginatorKind::LengthAware) {
            $properties[] = $this->prop('last_page', ['type' => 'integer']);
            $properties[] = $this->prop('last_page_url', ['type' => 'string']);
            $properties[] = $this->prop('total', ['type' => 'integer']);
            // links carries {url, label, active} objects; modelled as an untyped array for now.
            $properties[] = $this->prop('links', ['type' => 'array', 'items' => new OA\Items([])]);
        }

        return new OA\Schema(['type' => 'object', 'properties' => $properties]);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function prop(string $name, array $definition): OA\Property
    {
        return new OA\Property(['property' => $name, ...$definition]);
    }
}
