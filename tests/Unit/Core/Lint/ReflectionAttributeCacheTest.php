<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\ReflectionAttributeCache;
use Radiergummi\OpenApi\Tests\Unit\Core\Lint\Fixtures\RacFirstAttribute;
use Radiergummi\OpenApi\Tests\Unit\Core\Lint\Fixtures\RacNoAttributes;
use Radiergummi\OpenApi\Tests\Unit\Core\Lint\Fixtures\RacOtherAttribute;
use Radiergummi\OpenApi\Tests\Unit\Core\Lint\Fixtures\RacRepeatedAttribute;
use Radiergummi\OpenApi\Tests\Unit\Core\Lint\Fixtures\RacWithAttributes;
use Radiergummi\OpenApi\Tests\Unit\Core\Lint\Fixtures\RacWithRepeatedAttributes;

uses()->group('openapi', 'lint');

it('reuses the same ReflectionClass instance across calls', function (): void {
    $cache = new ReflectionAttributeCache();

    $first = $cache->reflectionClass(RacWithAttributes::class);
    $second = $cache->reflectionClass(RacWithAttributes::class);

    expect($second)->toBe($first);
});

it('classAttributes returns identical bucket entries on repeated calls', function (): void {
    $cache = new ReflectionAttributeCache();

    $first = $cache->classAttributes(RacWithAttributes::class, RacFirstAttribute::class);
    $second = $cache->classAttributes(RacWithAttributes::class, RacFirstAttribute::class);

    expect($first)->toHaveCount(1)
        ->and($second)->toBe($first);
});

it('classAttributes returns only the matching attribute FQCN', function (): void {
    $cache = new ReflectionAttributeCache();

    $matches = $cache->classAttributes(RacWithAttributes::class, RacFirstAttribute::class);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->getName())->toBe(RacFirstAttribute::class);
});

it('returns an empty list for attributes that are not present', function (): void {
    $cache = new ReflectionAttributeCache();

    expect($cache->classAttributes(RacNoAttributes::class, RacFirstAttribute::class))->toBe([])
        ->and($cache->classAttributes(RacWithAttributes::class, RacOtherAttribute::class))->toBe([]);
});

it('returns every occurrence when the same attribute is repeated', function (): void {
    $cache = new ReflectionAttributeCache();

    $matches = $cache->classAttributes(
        RacWithRepeatedAttributes::class,
        RacRepeatedAttribute::class,
    );

    expect($matches)->toHaveCount(2)
        ->and($matches[0]->getName())->toBe(RacRepeatedAttribute::class)
        ->and($matches[1]->getName())->toBe(RacRepeatedAttribute::class);
});

it('accepts a ReflectionClass directly via attributes()', function (): void {
    $cache = new ReflectionAttributeCache();
    $reflector = new ReflectionClass(RacWithAttributes::class);

    $first = $cache->attributes($reflector, RacFirstAttribute::class);
    $second = $cache->attributes($reflector, RacFirstAttribute::class);

    expect($first)->toHaveCount(1)
        ->and($second)->toBe($first);
});

it('buckets attributes off a ReflectionMethod', function (): void {
    $cache = new ReflectionAttributeCache();
    $method = (new ReflectionClass(RacWithAttributes::class))->getMethod('annotated');

    $matches = $cache->attributes($method, RacFirstAttribute::class);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->getName())->toBe(RacFirstAttribute::class);
});
