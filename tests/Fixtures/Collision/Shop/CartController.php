<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Collision\Shop;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\RequestField;

/**
 * Test fixture — shares its short name (`CartController`) with
 * {@see \Radiergummi\OpenApi\Tests\Fixtures\Collision\Admin\CartController} but declares a
 * different `#[RequestField]` set, to exercise the request-body component-key collision guard.
 */
class CartController extends Controller
{
    #[RequestField('coupon_code', required: true, type: 'string')]
    public function storeCoupon(Request $request): array
    {
        return [];
    }
}
