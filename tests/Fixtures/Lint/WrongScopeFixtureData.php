<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\PathParam;
use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\FieldAttributeWrongScope;
use Spatie\LaravelData\Data;

/**
 * Fixture Data class for {@see FieldAttributeWrongScope}.
 *
 * The `$misplaced` property carries #[PathParam], which belongs on a URI parameter, not a
 * request-body field — the rule must flag it.
 */
final class WrongScopeFixtureData extends Data
{
    public function __construct(
        #[PathParam(description: 'Wrong — PathParam on a request-body field.')]
        public string $misplaced,
        public string $correct,
    ) {}
}
