<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Overrides any subset of the auto-derived operation metadata. Null properties fall back to the
 * auto value (docblock, route name, controller-derived tags). Method-level wins over class-level.
 *
 * `tags` merges with the controller-derived set by default; `replace: true` drops the auto set.
 * For purely additive tagging, prefer {@see Tag}. `streaming: true` advertises `text/event-stream`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Operation
{
    /**
     * @param null|non-empty-string       $summary
     * @param null|non-empty-string       $description
     * @param null|non-empty-string       $operationId
     * @param null|list<non-empty-string> $tags
     */
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $operationId = null,
        public ?array $tags = null,
        public bool $replace = false,
        public bool $streaming = false,
    ) {}
}
