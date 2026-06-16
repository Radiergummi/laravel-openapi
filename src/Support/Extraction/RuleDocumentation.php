<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use Radiergummi\OpenApi\Contracts\Extraction\SelfDocumentingRule;

/**
 * Metadata returned by a {@see SelfDocumentingRule}. All fields are optional. Schema constraints
 * are written only when the target descriptor has no value yet; `$description` is appended rather
 * than overwritten.
 */
final readonly class RuleDocumentation
{
    /**
     * @param null|list<float|int|string> $enum
     */
    public function __construct(
        public ?string $description = null,
        public ?string $type = null,
        public ?string $format = null,
        public ?string $pattern = null,
        public ?array $enum = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public mixed $example = null,
    ) {}
}
