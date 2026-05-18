<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;

/**
 * Adds an OpenAPI tag to an operation in addition to whatever tags are
 * auto-derived from the namespace.
 *
 * Use this when an endpoint logically belongs in multiple groups, or to
 * relabel a single endpoint without overriding the entire tag set the way
 * {@see Operation::$tags} would.
 *
 * Class-level and method-level tags are merged. Duplicates are deduplicated.
 *
 * ```php
 * #[OpenApi\Tag('Beta')]
 * public function experimentalEndpoint(): JsonResource { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Tag
{
    public function __construct(public string $name) {}
}
