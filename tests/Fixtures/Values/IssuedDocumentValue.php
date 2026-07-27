<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Values;

use DateTimeImmutable;

/**
 * A non-Model value object carrying a date-typed public property, which types both a bare
 * `$this->issuedAt` read and a `$this->issuedAt->format(…)` call.
 */
final readonly class IssuedDocumentValue
{
    public function __construct(
        public DateTimeImmutable $issuedAt,
    ) {}
}
