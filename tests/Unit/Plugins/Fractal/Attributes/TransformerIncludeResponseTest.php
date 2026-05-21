<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Attributes;

use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
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
