<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Values;

use DateTimeImmutable;

/**
 * A non-Model value object carrying a date-typed public property: a bare `$this->issuedAt` reads
 * types from it, while `$this->issuedAt->format(…)` must not.
 */
final readonly class IssuedDocumentValue
{
    public function __construct(
        public DateTimeImmutable $issuedAt,
    ) {}
}
