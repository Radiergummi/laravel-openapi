<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\InlineValidation\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One half of a same-short-name controller pair (with the Shop namespace twin) pinning that
 * inline-validation component keys disambiguate instead of silently sharing one schema.
 */
class CouponController
{
    public function storeCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'discount' => 'required|integer',
        ]);

        return new JsonResponse($validated, 201);
    }
}
