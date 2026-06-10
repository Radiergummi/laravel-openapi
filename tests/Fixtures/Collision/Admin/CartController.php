<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Collision\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\RequestField;

/**
 * Test fixture — shares its short name (`CartController`) with
 * {@see \Radiergummi\OpenApi\Tests\Fixtures\Collision\Shop\CartController} but declares a
 * different `#[RequestField]` set, to exercise the request-body component-key collision guard.
 */
class CartController extends Controller
{
    #[RequestField('discount_percent', required: true, type: 'integer')]
    public function storeCoupon(Request $request): array
    {
        return [];
    }
}
