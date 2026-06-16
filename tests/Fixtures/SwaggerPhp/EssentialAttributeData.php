<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

// The authored schema carries a human-written description inference cannot derive, so inference does
// not subsume it — the annotation is essential and must NOT be flagged for removal.
#[OA\Schema(schema: 'Essential', description: 'A human-written description inference cannot derive.')]
final class EssentialAttributeData extends Data
{
    public function __construct(
        public string $name,
    ) {}
}
