<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Alpha;

use Radiergummi\OpenApi\Tests\Fixtures\Beta\SelfRefData as BetaSelfRefData;
use Spatie\LaravelData\Data;

/**
 * Fixture for OAPI-008: same basename as {@see BetaSelfRefData} in a different namespace, with a
 * self-referential property.
 */
final class SelfRefData extends Data
{
    public function __construct(
        public string $name,
        public ?self $child = null,
    ) {}
}
