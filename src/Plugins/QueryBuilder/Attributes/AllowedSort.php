<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes;

use Attribute;

/**
 * Declares the `spatie/laravel-query-builder` allowed sorts for an endpoint —
 * emitted as the `sort` query-string parameter. Method-level, not repeatable.
 *
 * ```php
 * #[AllowedSort(['name', 'created_at'])]
 * public function index(): JsonResponse { … }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class AllowedSort
{
    /**
     * @param list<string> $fields Sortable field names. The wire syntax allows a `-` prefix for descending order.
     */
    public function __construct(
        public array $fields,
    ) {}
}
