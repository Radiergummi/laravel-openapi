<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

/**
 * A plain typed value object (not an Eloquent model) wrapped by {@see ValueObjectBackedResource}.
 * Mirrors the Koel `App\Values\GenreSummary` shape from the survey corpus.
 */
final readonly class GenreSummaryValue
{
    public function __construct(
        public string $publicId,
        public string $name,
        public int $songCount,
        public float $length,
        public ?string $description,
        public GenreKindValue $kind,
    ) {}
}
