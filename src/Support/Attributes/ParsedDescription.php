<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Attributes;

/**
 * Result of running {@see DescriptionDirectives::parse()} over a field-attribute description.
 *
 * `$cleanDescription` has directive lines stripped. `$example` comes from `@example`. `$suppressExample`
 * is true when `@no-example` is present. `$enum` comes from `@enum a,b,c` with lexical coercion.
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
