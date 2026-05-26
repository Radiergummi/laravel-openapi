<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes\Support;

/**
 * Result of running {@see DescriptionDirectives::parse()} over a field-attribute description.
 *
 * - {@see $cleanDescription} is the description with all directive lines stripped.
 * - {@see $example} is the value declared by `Example: …` (or `null` if none / suppressed).
 * - {@see $suppressExample} is `true` when `No-example` was present.
 * - {@see $enum} is the value list declared by `Enum: a,b,c` (or `null` if none).
 */
final readonly class ParsedDescription
{
    /**
     * @param null|list<string> $enum
     */
    public function __construct(
        public ?string $cleanDescription,
        public mixed $example = null,
        public bool $suppressExample = false,
        public ?array $enum = null,
    ) {}
}
