<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Registry\SchemaName;

use Radiergummi\OpenApi\Attributes\SchemaName;
use Spatie\LaravelData\Data;

/** Fixture: explicitly pins the name 'Plain', which {@see Plain} would otherwise derive. */
#[SchemaName('Plain')]
final class PinsPlain extends Data {}
