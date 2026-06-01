<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Lint\Rules\DeprecatedAttribute;

/**
 * Fixture controller with a class-level deprecated attribute for testing that
 * {@see DeprecatedAttribute} uses the correct message wording when the deprecated attribute is
 * on the controller class rather than a method.
 */
#[DeprecatedTestAttribute]
final class DeprecatedAttrClassController
{
    public function index(): JsonResponse
    {
        return response()->json();
    }
}
