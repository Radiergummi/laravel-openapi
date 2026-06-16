<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaEquivalence;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaSubsumption;

uses()->group('openapi');

function schemaSubsumption(): SchemaSubsumption
{
    return new SchemaSubsumption(new SchemaEquivalence());
}

it('subsumes with an empty candidate exactly as plain subsumption does', function (): void {
    // The ∅ path must behave identically to SchemaEquivalence::subsumes — this is the redundant case
    // every shipped migration rule relies on.
    $inferred = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'id', 'type' => 'integer']),
    ]]);
    $authored = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'id', 'type' => 'integer']),
    ]]);

    expect(schemaSubsumption()->subsumes($inferred, $authored, []))->toBeTrue();
});

it('does not subsume on inference alone when the author carries a property description inference lacks', function (): void {
    // Inference produced the property but no description; the authored block adds one. Without a
    // candidate replacement this is essential — not redundant.
    $inferred = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'name', 'type' => 'string']),
    ]]);
    $authored = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'name', 'type' => 'string', 'description' => 'The user name']),
    ]]);

    expect(schemaSubsumption()->subsumes($inferred, $authored, []))->toBeFalse();
});

it('subsumes once a candidate replacement supplies the missing property description', function (): void {
    // The candidate-replacement seam #122 part 2 targets: inference ⊕ candidate. A `#[ResponseField]`
    // would contribute the same description an authored `#[OA\Property]` carries, so once that
    // candidate is folded onto the inferred schema the authored block is reproduced and becomes
    // replaceable. Proves the seam flips the verdict — not merely that it compiles.
    $inferred = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'name', 'type' => 'string']),
    ]]);
    $authored = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'name', 'type' => 'string', 'description' => 'The user name']),
    ]]);
    $candidate = [
        new OA\Property(['property' => 'name', 'description' => 'The user name']),
    ];

    expect(schemaSubsumption()->subsumes($inferred, $authored, $candidate))->toBeTrue();
});
