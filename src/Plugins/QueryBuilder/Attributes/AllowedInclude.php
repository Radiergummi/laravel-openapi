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
 * Declares the `spatie/laravel-query-builder` allowed includes for an endpoint — emitted as the
 * `include` query-string parameter. Method-level, not repeatable.
 *
 * ```php
 * #[AllowedInclude(['owner', 'tags'])]
 * public function index(): JsonResponse { … }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class AllowedInclude
{
    /**
     * @param list<string> $names Includable relationship names.
     */
    public function __construct(
        public array $names,
    ) {}
}
