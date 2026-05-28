<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Support\Routing\ThrowsExtractor;
use Radiergummi\OpenApi\Tests\Unit\Support\Routing\Fixtures\ThrowingController;

uses()->group('routing', 'openapi');

beforeEach(function (): void {
    $this->extractor = ThrowsExtractor::create();
});

it('extracts multiple @throws tags as FQCNs', function (): void {
    $method = new ReflectionMethod(ThrowingController::class, 'multiple');

    expect($this->extractor->extract($method))
        ->toBe(['InvalidArgumentException', 'RuntimeException']);
});

it('flattens a compound @throws type into separate FQCNs', function (): void {
    $method = new ReflectionMethod(ThrowingController::class, 'compound');

    expect($this->extractor->extract($method))
        ->toBe(['LogicException', 'RuntimeException']);
});

it('returns an empty list when the method has no docblock', function (): void {
    $method = new ReflectionMethod(ThrowingController::class, 'noDocblock');

    expect($this->extractor->extract($method))->toBe([]);
});

it('returns an empty list when the docblock has no @throws tags', function (): void {
    $method = new ReflectionMethod(ThrowingController::class, 'noThrows');

    expect($this->extractor->extract($method))->toBe([]);
});

it('resolves @throws FQCNs in closure docblocks via a context-free fallback', function (): void {
    /** @throws RuntimeException */
    $closure = static function (): void {};

    $reflector = new ReflectionFunction($closure);

    expect($this->extractor->extract($reflector))->toBe(['RuntimeException']);
});
