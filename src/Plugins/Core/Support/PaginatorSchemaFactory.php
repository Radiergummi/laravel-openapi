<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Enums\PaginatorKind;

/**
 * Builds the flat OpenAPI schema for a bare Laravel paginator (`toArray()` shape).
 *
 * The `{data, links, meta}` resource envelope is a separate shape produced only when a
 * `ResourceCollection` wraps a paginator; that is handled by the ApiResources plugin.
 */
final class PaginatorSchemaFactory
{
    /**
     * Builds the response-body schema for the given paginator kind, wrapping the item schema in `data`.
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
