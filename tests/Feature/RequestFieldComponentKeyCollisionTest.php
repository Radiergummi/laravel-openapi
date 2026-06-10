<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Collision\Admin\CartController as AdminCartController;
use Radiergummi\OpenApi\Tests\Fixtures\Collision\Shop\CartController as ShopCartController;

uses()->group('openapi');

beforeEach(function (): void {
    Route::post('/oa-fixture/admin/cart/coupon', [AdminCartController::class, 'storeCoupon']);
    Route::post('/oa-fixture/shop/cart/coupon', [ShopCartController::class, 'storeCoupon']);
    $this->spec = generateSpec();
});

it('keeps same-short-name controllers in distinct request-body components', function (): void {
    $adminRef = $this->spec['paths']['/oa-fixture/admin/cart/coupon']['post']
        ['requestBody']['content']['application/json']['schema']['$ref'] ?? null;
    $shopRef = $this->spec['paths']['/oa-fixture/shop/cart/coupon']['post']
        ['requestBody']['content']['application/json']['schema']['$ref'] ?? null;

    expect($adminRef)->toStartWith('#/components/schemas/')
        ->and($shopRef)->toStartWith('#/components/schemas/')
        ->and($adminRef)->not->toBe($shopRef);

    $adminKey = substr((string) $adminRef, strlen('#/components/schemas/'));
    $shopKey = substr((string) $shopRef, strlen('#/components/schemas/'));

    expect($this->spec['components']['schemas'][$adminKey]['properties'])->toHaveKey('discount_percent')
        ->and($this->spec['components']['schemas'][$adminKey]['properties'])->not->toHaveKey('coupon_code')
        ->and($this->spec['components']['schemas'][$shopKey]['properties'])->toHaveKey('coupon_code')
        ->and($this->spec['components']['schemas'][$shopKey]['properties'])->not->toHaveKey('discount_percent');
});
