<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Discriminator;

use Spatie\LaravelData\Data;

/**
 * Circle variant for the OAPI-027 discriminator fixture.
 */
final class CircleData extends Data
{
    public function __construct(
        public string $type,
        public float $radius,
    ) {}
}
