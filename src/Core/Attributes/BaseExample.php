<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use InvalidArgumentException;

/**
 * Shared payload shape for {@see Example} (request body) and
 * {@see ResponseExample} (response). Lets the extractor consume both kinds
 * of attribute through a single type without a callback dispatch.
 *
 * Exactly one of `value` or `file` must be provided. When `file` is given it
 * is resolved relative to the project root at spec-generation time — the
 * attribute constructor itself performs no I/O (attribute construction must
 * stay side-effect-free).
 */
abstract readonly class BaseExample
{
    /**
     * @param mixed       $value Inline example payload (PHP array / scalar). Mutually exclusive with `$file`.
     * @param null|string $file  Path to a JSON file relative to the project root. Mutually exclusive with `$value`.
     *
     * @throws InvalidArgumentException When both or neither of `$value` / `$file` are provided.
     */
    public function __construct(
        public string $name,
        public mixed $value = null,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $file = null,
    ) {
        $hasValue = $value !== null;
        $hasFile  = $file !== null;

        if ($hasValue && $hasFile) {
            throw new InvalidArgumentException(
                "BaseExample: provide either 'value' or 'file', not both (name: {$name}).",
            );
        }

        if (!$hasValue && !$hasFile) {
            throw new InvalidArgumentException(
                "BaseExample: either 'value' or 'file' must be provided (name: {$name}).",
            );
        }
    }
}
