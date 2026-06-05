<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use Radiergummi\OpenApi\Contracts\Extraction\SelfDocumentingRule;

/**
 * Documentation metadata returned by a {@see SelfDocumentingRule} so the OpenAPI generator can
 * describe the rule without knowing its internals.
 *
 * Every field is optional. Fields left `null` do not modify the {@see FieldDescriptor} they are
 * applied to. Type / format / pattern / enum / minLength / maxLength / minimum / maximum / example
 * are written only when the descriptor has no value yet, so rule self-documentation cannot clobber
 * a constraint already established by a sibling rule. {@see $description} is appended to any
 * pre-existing description rather than overwriting it.
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
