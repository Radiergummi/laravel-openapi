<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Registry\SchemaName;

use Radiergummi\OpenApi\Attributes\SchemaName;
use Spatie\LaravelData\Data;

/** Fixture: collides with {@see DuplicateB} on the same explicit schema name. */
#[SchemaName('Shared')]
final class DuplicateA extends Data {}
