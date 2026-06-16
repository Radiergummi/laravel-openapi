<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use InvalidArgumentException;

/**
 * Shared payload for {@see Example} and {@see ResponseExample}.
 *
 * Exactly one of `value` or `file` must be provided. `file` is resolved relative to the project
 * root at spec-generation time; the constructor performs no I/O.
 */
abstract readonly class BaseExample
{
    /**
     * @param non-empty-string      $name
     * @param mixed                 $value       Inline example payload. Mutually exclusive with `$file`.
     * @param null|non-empty-string $summary
     * @param null|non-empty-string $description
     * @param null|non-empty-string $file        JSON file path relative to the project root. Mutually exclusive with `$value`.
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
        $hasFile = $file !== null;

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
