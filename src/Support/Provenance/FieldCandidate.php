<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Provenance;

use Radiergummi\OpenApi\Support\Attributes\FieldDefault;

/**
 * One candidate value for a field, in a precedence-ordered list handed to {@see ResolvedField}.
 *
 * A candidate is *present* when its {@see self::$value} is anything other than {@see FieldDefault::Unset};
 * the highest-precedence present candidate wins, and every lower-precedence present candidate is
 * recorded as superseded. {@see self::$supersededLabel} is how the candidate reads when it loses
 * (e.g. `convention 'Show Flight'`); it defaults to the source when omitted.
 *
 * @internal
 */
final readonly class FieldCandidate
{
    /**
     * @param mixed   $value           The candidate value, or {@see FieldDefault::Unset} when absent.
     * @param string  $source          Attribute name + scope, resolver short-class, or `default`.
     * @param string  $reason          Short human string, e.g. `store → POST`, `author override`.
     * @param ?string $supersededLabel How this candidate reads when it loses; defaults to `$source`.
     */
    public function __construct(
        public mixed $value,
        public string $source,
        public string $reason,
        public ?string $supersededLabel = null,
    ) {}

    /**
     * A present candidate: a value the author, docblock, resolver, or convention actually supplied.
     */
    public static function present(
        mixed $value,
        string $source,
        string $reason,
        ?string $supersededLabel = null,
    ): self {
        return new self($value, $source, $reason, $supersededLabel);
    }

    /**
     * An absent candidate: this source had nothing to say. Never wins, never superseded.
     */
    public static function absent(string $source, string $reason): self
    {
        return new self(FieldDefault::Unset, $source, $reason);
    }

    public function isPresent(): bool
    {
        return $this->value !== FieldDefault::Unset;
    }
}
