<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\Registry\Bar\CreateData as BarCreateData;
use Radiergummi\OpenApi\Tests\Fixtures\Registry\Baz\Bar\CreateData as BazBarCreateData;
use Radiergummi\OpenApi\Tests\Fixtures\Registry\Domain\Data\Alpha\CreateData as AlphaCreateData;
use Radiergummi\OpenApi\Tests\Fixtures\Registry\Domain\Data\Beta\CreateData as BetaCreateData;
use Radiergummi\OpenApi\Tests\Fixtures\Registry\Foo\Bar\CreateData as FooBarCreateData;
use Radiergummi\OpenApi\Tests\Fixtures\Registry\Foo\CreateData as FooCreateData;
use Radiergummi\OpenApi\Tests\Fixtures\Registry\Projects\CreateData as ProjectsCreateData;
use Radiergummi\OpenApi\Tests\Fixtures\Registry\Qux\Bar\CreateData as QuxBarCreateData;

uses()->group('openapi');

// region Key derivation — no-collision cases

it('uses the bare basename when no collision exists', function (): void {
    $registry = new ComponentSchemaRegistry();

    $key = $registry->reserveKey(ProjectsCreateData::class);

    expect($key)->toBe('CreateData');
});

it('reuses the same key on repeated calls for the same class', function (): void {
    $registry = new ComponentSchemaRegistry();

    $first  = $registry->reserveKey(ProjectsCreateData::class);
    $second = $registry->reserveKey(ProjectsCreateData::class);

    expect($first)->toBe($second);
});

// endregion

// region Key derivation — two-class collision

it('disambiguates two classes with the same basename using the nearest non-generic ancestor', function (): void {
    $registry = new ComponentSchemaRegistry();

    $keyA = $registry->reserveKey(FooCreateData::class);
    $keyB = $registry->reserveKey(BarCreateData::class);

    expect($keyA)->toBe('CreateData')
        ->and($keyB)->toBe('Bar.CreateData')
        ->and($keyA)->not->toBe($keyB);
});

it('skips generic namespace segments (Data, Domain) when disambiguating', function (): void {
    $registry = new ComponentSchemaRegistry();

    // Both classes live directly under a 'Data' sub-namespace; the disambiguator
    // must skip 'Data' and 'Domain' and reach the meaningful ancestor.
    $keyA = $registry->reserveKey(AlphaCreateData::class);
    $keyB = $registry->reserveKey(BetaCreateData::class);

    expect($keyA)->toBe('CreateData')
        ->and($keyB)->toBe('Beta.CreateData')
        ->and($keyA)->not->toBe($keyB);
});

// endregion

// region Key derivation — three-class collision (OAPI-037 acceptance criterion)

it('produces distinct keys for three classes sharing a basename and immediate ancestor', function (): void {
    // All three share basename 'CreateData' and immediate ancestor 'Bar',
    // so the first disambiguation level ('Bar.CreateData') is shared too.
    // The algorithm must walk further up for the third class.
    $registry = new ComponentSchemaRegistry();

    $keyFoo = $registry->reserveKey(FooBarCreateData::class);
    $keyBaz = $registry->reserveKey(BazBarCreateData::class);
    $keyQux = $registry->reserveKey(QuxBarCreateData::class);

    expect($keyFoo)->not->toBe($keyBaz)
        ->and($keyFoo)->not->toBe($keyQux)
        ->and($keyBaz)->not->toBe($keyQux);
});

it('uses readable namespace prefixes for the three-class collision', function (): void {
    $registry = new ComponentSchemaRegistry();

    $keyFoo = $registry->reserveKey(FooBarCreateData::class);
    $keyBaz = $registry->reserveKey(BazBarCreateData::class);
    $keyQux = $registry->reserveKey(QuxBarCreateData::class);

    // First class gets the bare basename; second gets one ancestor prefix;
    // third must walk two levels up to be unique.
    expect($keyFoo)->toBe('CreateData')
        ->and($keyBaz)->toBe('Bar.CreateData')
        ->and($keyQux)->toBe('Qux.Bar.CreateData');
});

// endregion

// region keyFor / has round-trip

it('returns the same key via keyFor() after reserveKey()', function (): void {
    $registry = new ComponentSchemaRegistry();

    $key = $registry->reserveKey(ProjectsCreateData::class);

    expect($registry->keyFor(ProjectsCreateData::class))->toBe($key)
        ->and($registry->isRegisteredOrReserved(ProjectsCreateData::class))->toBeTrue();
});

it('returns null from keyFor() for a class that has not been reserved', function (): void {
    $registry = new ComponentSchemaRegistry();

    expect($registry->keyFor(ProjectsCreateData::class))->toBeNull()
        ->and($registry->isRegisteredOrReserved(ProjectsCreateData::class))->toBeFalse();
});

// endregion

// region registerNamed — key reservation against user-class collisions

it('reserves the key in registerNamed so a later user-class registration with the same basename disambiguates instead of overwriting', function (): void {
    $registry = new ComponentSchemaRegistry();

    $envelopeSchema = new OA\Schema([
        'type'       => 'object',
        'required'   => ['message'],
        'properties' => [
            new OA\Property(['property' => 'message', 'type' => 'string']),
        ],
    ]);
    $registry->registerNamed('Error', $envelopeSchema);

    $userClass = 'App\\Errors\\Error';
    $userKey = $registry->reserveKey($userClass);

    expect($userKey)->not->toBe('Error');
    expect($registry->hasKey('Error'))->toBeTrue();
    expect($registry->keyFor($userClass))->toBe($userKey);
});

it('keeps the envelope schema intact when a user class with the same basename is registered after', function (): void {
    $registry = new ComponentSchemaRegistry();

    $envelopeSchema = new OA\Schema(['type' => 'object']);
    $registry->registerNamed('Error', $envelopeSchema);

    $registry->register('App\\Errors\\Error', new OA\Schema(['type' => 'string']));

    $schemas = $registry->all();
    $errorEntry = null;

    foreach ($schemas as $schema) {
        if ($schema->schema === 'Error') {
            $errorEntry = $schema;

            break;
        }
    }

    expect($errorEntry)->toBe($envelopeSchema);
});

// endregion

// region Hash fallback — all namespace segments are generic (empty prefix)

it('produces a valid (no leading dot) key when all namespace segments are generic', function (): void {
    // Two synthetic FQCNs whose only namespace segments are 'Data' and 'Domain' — both
    // are filtered, so $prefix stays '' when the hash fallback fires.
    // reserveKey() does not require the class to exist, it only uses the string.
    $registry = new ComponentSchemaRegistry();

    $classA = 'Data\\CreateData';
    $classB = 'Domain\\CreateData';

    $keyA = $registry->reserveKey($classA);
    $keyB = $registry->reserveKey($classB);

    // Neither key may start with a dot (invalid OpenAPI component key).
    expect($keyA)->not->toStartWith('.')
        ->and($keyB)->not->toStartWith('.')
        ->and($keyA)->not->toBe($keyB);
});

// endregion
