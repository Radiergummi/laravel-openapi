<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Attributes;

/**
 * Result of running {@see DescriptionDirectives::parse()} over a field-attribute description.
 *
 * - {@see $cleanDescription} is the description with all directive lines stripped (or `null`
 *   when the description was null, empty, or whitespace-only).
 * - {@see $example} is the value declared by `@example …` (or `null` if none / suppressed).
 * - {@see $suppressExample} is `true` when `@no-example` was present.
 * - {@see $enum} is the value list declared by `@enum a,b,c` (or `null` if none); tokens are
 *   coerced by lexical shape so integer-looking values become ints, etc.
 *
 * @internal
 */
final readonly class ParsedDescription
{
    /**
     * @param null|list<bool|float|int|string> $enum
     */
    public function __construct(
        public ?string $cleanDescription,
        public mixed $example = null,
        public bool $suppressExample = false,
        public ?array $enum = null,
    ) {}
}
