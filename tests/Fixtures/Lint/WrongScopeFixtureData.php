<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Core\Attributes\PathParam;
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
