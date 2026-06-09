<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Attributes\SchemaName;
use Spatie\LaravelData\Data;

/** Data class whose component key is pinned with #[SchemaName], decoupled from the class name. */
#[SchemaName('CustomerProfile')]
final class SchemaNameData extends Data
{
    public function __construct(
        public string $name,
    ) {}
}
