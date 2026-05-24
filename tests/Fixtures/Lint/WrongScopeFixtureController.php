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
use Radiergummi\OpenApi\Core\Attributes\RequestField;
use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\FieldAttributeWrongScope;

/**
 * Fixture controller for {@see FieldAttributeWrongScope}.
 */
final class WrongScopeFixtureController
{
    /**
     * `$id` carries #[RequestField], which belongs on a request-body field,
     * not a URI parameter — the rule must flag it.
     */
    public function requestFieldOnRouteParam(
        #[RequestField(description: 'Wrong — RequestField on a URI parameter.')]
        string $id,
    ): void {}

    /**
     * `$payload` is a Data class whose `$misplaced` property carries #[PathParam].
     */
    public function pathParamOnDataProperty(WrongScopeFixtureData $payload): void {}

    /**
     * A correctly-annotated action — no findings expected.
     */
    public function correct(
        #[PathParam(description: 'Correct — PathParam on a URI parameter.')]
        string $id,
    ): void {}
}
