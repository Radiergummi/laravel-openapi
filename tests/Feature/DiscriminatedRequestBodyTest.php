<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\DiscriminatedRequestFixtureController;

uses()->group('openapi');

function discriminatedBodySchema(array $spec, string $path): array
{
    $ref = $spec['paths'][$path]['post']['requestBody']['content']['application/json']['schema']['$ref'] ?? null;
    expect($ref)->toStartWith('#/components/schemas/');

    $key = substr((string) $ref, strlen('#/components/schemas/'));

    return $spec['components']['schemas'][$key] ?? [];
}

it('emits oneOf + discriminator for inline-field branches', function (): void {
    Route::post('/oa-139/inline', [DiscriminatedRequestFixtureController::class, 'inline']);
    $spec = generateSpec();

    $schema = discriminatedBodySchema($spec, '/oa-139/inline');

    expect($schema)->toHaveKey('oneOf')
        ->and($schema['discriminator']['propertyName'])->toBe('provider')
        ->and(array_keys($schema['discriminator']['mapping']))->toBe(['aws', 'hetzner'])
        ->and($schema['oneOf'])->toHaveCount(2);
});

it('builds each inline branch as its own object component', function (): void {
    Route::post('/oa-139/inline', [DiscriminatedRequestFixtureController::class, 'inline']);
    $spec = generateSpec();

    $awsRef = $spec['components']['schemas'][substr((string) discriminatedBodySchema($spec, '/oa-139/inline')['discriminator']['mapping']['aws'], strlen('#/components/schemas/'))];

    expect($awsRef['type'])->toBe('object')
        ->and($awsRef['properties'])->toHaveKeys(['region', 'access_key'])
        ->and($awsRef['required'])->toContain('region')
        ->and($awsRef['required'])->toContain('access_key');
});

it('resolves a class-string branch through the ref chain', function (): void {
    Route::post('/oa-139/mixed', [DiscriminatedRequestFixtureController::class, 'mixed']);
    $spec = generateSpec();

    $schema = discriminatedBodySchema($spec, '/oa-139/mixed');

    expect($schema['discriminator']['mapping']['custom'])->toBe('#/components/schemas/CircleData')
        ->and($spec['components']['schemas'])->toHaveKey('CircleData');
})->group('plugin:spatie-data');

it('emits a finding and skips a variant whose sanitised key collides with an earlier variant', function (): void {
    Route::post('/oa-139/colliding', [DiscriminatedRequestFixtureController::class, 'colliding']);
    $spec = generateSpec();

    $schema = discriminatedBodySchema($spec, '/oa-139/colliding');
    $mappingKeys = array_keys($schema['discriminator']['mapping']);

    // Only one of the two colliding variants should survive.
    expect($mappingKeys)->toHaveCount(1)
        ->and($schema['oneOf'])->toHaveCount(1);
});

it('auto-injects the discriminator property as a single-value enum string', function (): void {
    Route::post('/oa-139/injection', [DiscriminatedRequestFixtureController::class, 'injection']);
    $spec = generateSpec();

    $mapping = discriminatedBodySchema($spec, '/oa-139/injection')['discriminator']['mapping'];
    $branchA = $spec['components']['schemas'][substr((string) $mapping['a'], strlen('#/components/schemas/'))];

    expect($branchA['properties']['type']['type'])->toBe('string')
        ->and($branchA['properties']['type']['enum'])->toBe(['a'])
        ->and($branchA['required'])->toContain('type');
});

it('lets an authored discriminator field win over injection', function (): void {
    Route::post('/oa-139/injection', [DiscriminatedRequestFixtureController::class, 'injection']);
    $spec = generateSpec();

    $mapping = discriminatedBodySchema($spec, '/oa-139/injection')['discriminator']['mapping'];
    $branchB = $spec['components']['schemas'][substr((string) $mapping['b'], strlen('#/components/schemas/'))];

    expect($branchB['properties']['type']['description'])->toBe('Authored discriminator.')
        ->and($branchB['properties']['type'])->not->toHaveKey('enum');
});

it('emits the expected discriminated request-body shape', function (): void {
    Route::post('/oa-139/inline', [DiscriminatedRequestFixtureController::class, 'inline']);
    $schema = discriminatedBodySchema(generateSpec(), '/oa-139/inline');

    expect($schema['discriminator'])->toBe([
        'propertyName' => 'provider',
        'mapping' => [
            'aws' => '#/components/schemas/DiscriminatedRequestFixtureControllerInlineRequestAws',
            'hetzner' => '#/components/schemas/DiscriminatedRequestFixtureControllerInlineRequestHetzner',
        ],
    ]);
})->group('snapshot');
