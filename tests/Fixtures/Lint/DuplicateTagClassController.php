<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Attributes\Tag;
use Radiergummi\OpenApi\Lint\Rules\TagDuplicate;

/**
 * Fixture controller with class-level duplicate tags for testing the {@see TagDuplicate} rule.
 */
#[Tag('Admin')]
#[Tag('Admin')]
final class DuplicateTagClassController
{
    public function index(): JsonResponse
    {
        return response()->json();
    }
}
