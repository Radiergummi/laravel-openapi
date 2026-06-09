<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Registry\SchemaName;

use Radiergummi\OpenApi\Attributes\SchemaName;
use Spatie\LaravelData\Data;

/** Fixture: explicit #[SchemaName] overrides the derived basename ('Renamed'). */
#[SchemaName('PublicContract')]
final class Renamed extends Data {}
