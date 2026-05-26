<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;
use BackedEnum;

/**
 * Adds an OpenAPI tag to an operation in addition to whatever tags are auto-derived from the
 * namespace.
 *
 * Use this when an endpoint logically belongs in multiple groups, or to relabel a single endpoint
 * without overriding the entire tag set the way {@see Operation::$tags} would.
 *
 * Class-level and method-level tags are merged. Duplicates are deduplicated.
 *
 * The name may be a plain string or a {@see BackedEnum} case whose backing value is the tag
 * string — the latter lets consumers centralise tag taxonomies in an enum.
 *
 * ```php
 * #[OpenApi\Tag('Beta')]
 * public function experimentalEndpoint(): JsonResource { … }
 *
 * #[OpenApi\Tag(Tag::Identity)]
 * public function whoAmI(): UserResource { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Tag
{
    public function __construct(public string|BackedEnum $name) {}

    public function value(): string
    {
        return $this->name instanceof BackedEnum
            ? (string) $this->name->value
            : $this->name;
    }
}
