<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use ArrayObject;
use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Attributes\Response;
use stdClass;

/**
 * Fixture controller for testing the {@see \Radiergummi\OpenApi\Lint\Rules\ResponseRefUnresolvable}
 * rule. The ref targets are arbitrary existing classes — the test's fake resolver decides which
 * ones resolve.
 */
final class UnresolvableResponseRefController
{
    #[Response(status: 404, description: 'Resolvable ref', ref: stdClass::class)]
    public function withResolvableRef(): JsonResponse
    {
        return response()->json();
    }

    #[Response(status: 422, description: 'Unresolvable ref', ref: ArrayObject::class)]
    public function withUnresolvableRef(): JsonResponse
    {
        return response()->json();
    }

    #[Response(status: 500, description: 'No ref at all')]
    public function withoutRef(): JsonResponse
    {
        return response()->json();
    }
}
