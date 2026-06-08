<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaEquivalence;

uses()->group('openapi');

function schemaEquivalence(): SchemaEquivalence
{
    return new SchemaEquivalence();
}

/*
 * The rule fires when inference (`$control`) subsumes the authored annotation, i.e. reproduces
 * everything the author wrote and possibly more. `subsumes($broader, $narrower)` asks whether
 * `$narrower` is structurally contained in `$broader`.
 */
it('subsumes an identical schema', function (): void {
    $a = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'id', 'type' => 'integer']),
        new OA\Property(['property' => 'name', 'type' => 'string']),
    ]]);
    $b = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'id', 'type' => 'integer']),
        new OA\Property(['property' => 'name', 'type' => 'string']),
    ]]);

    expect(schemaEquivalence()->subsumes($a, $b))->toBeTrue();
});

it('subsumes regardless of property and required-member order', function (): void {
    $broader = new OA\Schema(['type' => 'object', 'required' => ['id', 'name'], 'properties' => [
        new OA\Property(['property' => 'id', 'type' => 'integer']),
        new OA\Property(['property' => 'name', 'type' => 'string']),
    ]]);
    $narrower = new OA\Schema(['type' => 'object', 'required' => ['name', 'id'], 'properties' => [
        new OA\Property(['property' => 'name', 'type' => 'string']),
        new OA\Property(['property' => 'id', 'type' => 'integer']),
    ]]);

    expect(schemaEquivalence()->subsumes($broader, $narrower))->toBeTrue();
});

it('subsumes when inference enriches the authored schema (extra example, extra property)', function (): void {
    // Inference adds an `example` to a property and discovers an extra `email` property the
    // author never annotated — the authored schema is still fully contained.
    $control = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'id', 'type' => 'integer', 'example' => 32]),
        new OA\Property(['property' => 'name', 'type' => 'string']),
        new OA\Property(['property' => 'email', 'type' => 'string']),
    ]]);
    $authored = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'id', 'type' => 'integer']),
        new OA\Property(['property' => 'name', 'type' => 'string']),
    ]]);

    expect(schemaEquivalence()->subsumes($control, $authored))->toBeTrue();
});

it('does not subsume when the author carries information inference lacks', function (): void {
    // The author wrote a description inference does not produce — load-bearing, must be kept.
    $control = new OA\Schema(['type' => 'string']);
    $authored = new OA\Schema(['type' => 'string', 'description' => 'The user email']);

    expect(schemaEquivalence()->subsumes($control, $authored))->toBeFalse();
});

it('does not subsume a genuine restriction the author added', function (): void {
    // `additionalProperties: false` is a key inference never emits, so the authored schema is
    // not contained — the restriction is preserved.
    $control = new OA\Schema(['type' => 'object']);
    $authored = new OA\Schema(['type' => 'object', 'additionalProperties' => false]);

    expect(schemaEquivalence()->subsumes($control, $authored))->toBeFalse();
});

it('does not subsume a differing scalar type', function (): void {
    expect(schemaEquivalence()->subsumes(new OA\Schema(['type' => 'string']), new OA\Schema(['type' => 'integer'])))
        ->toBeFalse();
});

it('ignores unset (UNDEFINED) properties', function (): void {
    $broader = new OA\Schema(['type' => 'string']);
    $narrower = new OA\Schema(['type' => 'string', 'format' => OpenApi\Generator::UNDEFINED]);

    expect(schemaEquivalence()->subsumes($broader, $narrower))->toBeTrue();
});

it('treats null against a present annotation as not subsumed', function (): void {
    expect(schemaEquivalence()->subsumes(null, new OA\Schema(['type' => 'string'])))->toBeFalse()
        ->and(schemaEquivalence()->subsumes(null, null))->toBeTrue();
});

it('does not subsume when a required member is absent from the broader side', function (): void {
    // List containment: the narrower `required` carries a member ('email') no broader element matches.
    $broader = new OA\Schema(['type' => 'object', 'required' => ['id']]);
    $narrower = new OA\Schema(['type' => 'object', 'required' => ['id', 'email']]);

    expect(schemaEquivalence()->subsumes($broader, $narrower))->toBeFalse();
});

it('drops UNDEFINED elements inside a list before comparing', function (): void {
    // A list with an UNDEFINED hole still subsumes once normalized down to its defined members.
    $broader = new OA\Schema(['type' => 'string', 'enum' => ['a', 'b']]);
    $narrower = new OA\Schema(['type' => 'string', 'enum' => ['a', OpenApi\Generator::UNDEFINED, 'b']]);

    expect(schemaEquivalence()->subsumes($broader, $narrower))->toBeTrue();
});
