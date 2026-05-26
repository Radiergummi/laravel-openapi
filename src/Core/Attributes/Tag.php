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

/**
 * Adds a tag in addition to the namespace-derived set. Use for purely additive tagging — to
 * replace the auto-derived set entirely, use {@see Operation::$tags} with `replace: true`.
 * Class- and method-level entries merge; duplicates dedupe.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Tag
{
    public function __construct(public string $name) {}
}
