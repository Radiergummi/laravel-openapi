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
