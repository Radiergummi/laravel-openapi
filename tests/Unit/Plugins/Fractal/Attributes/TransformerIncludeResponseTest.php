<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Attributes;

use Attribute;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
use ReflectionClass;
use stdClass;

it('stores an include name, transformer, and default flag', function (): void {
    $include = new TransformerInclude('author', transformer: stdClass::class, default: true);

    expect($include->name)->toBe('author')
        ->and($include->transformer)->toBe(stdClass::class)
        ->and($include->default)->toBeTrue();
});

it('defaults an include to non-default with no transformer', function (): void {
    $include = new TransformerInclude('comments');

    expect($include->transformer)->toBeNull()
        ->and($include->default)->toBeFalse();
});

it('binds an endpoint to a transformer with cardinality and pagination flags', function (): void {
    $response = new FractalResponse(transformer: stdClass::class, collection: true);

    expect($response->transformer)->toBe(stdClass::class)
        ->and($response->collection)->toBeTrue()
        ->and($response->paginated)->toBeFalse();
});

it('flags a paginated response', function (): void {
    $response = new FractalResponse(transformer: stdClass::class, paginated: true);

    expect($response->paginated)->toBeTrue();
});

it('targets methods only', function (): void {
    $attribute = (new ReflectionClass(FractalResponse::class))
        ->getAttributes(Attribute::class)[0]->newInstance();

    expect($attribute->flags & Attribute::TARGET_METHOD)->toBe(Attribute::TARGET_METHOD)
        ->and($attribute->flags & Attribute::TARGET_FUNCTION)->toBe(0);
});
