<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Values;

/**
 * A plain (non-Model) value object with statically-typed public properties.
 *
 * Mixes modelled scalars, a nullable scalar, an unmodelled union, an untyped property, and a
 * non-public property so a resource wrapping it exercises both the happy path and every refusal gate.
 */
final readonly class GenreSummaryValue
{
    /** A non-public property a resource key must never type from. */
    protected string $secret;

    public function __construct(
        public string $publicId,
        public string $name,
        public int $songCount,
        public float $length,
        public ?string $note,
        public int|string $mixedKey,
        public mixed $untyped,
    ) {
        $this->secret = 'hidden';
    }
}
