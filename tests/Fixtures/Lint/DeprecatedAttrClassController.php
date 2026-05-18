<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Http\JsonResponse;

/**
 * Fixture controller with a class-level deprecated attribute for testing that
 * {@see \Radiergummi\OpenApi\Core\Lint\Rules\DeprecatedAttribute} uses the correct message
 * wording when the deprecated attribute is on the controller class rather than a method.
 */
#[DeprecatedTestAttribute]
final class DeprecatedAttrClassController
{
    public function index(): JsonResponse
    {
        return response()->json();
    }
}
