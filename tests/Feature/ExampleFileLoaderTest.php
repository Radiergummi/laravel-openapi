<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route as RouteFacade;
use Radiergummi\OpenApi\Core\Attributes\Example;
use Radiergummi\OpenApi\Core\Generator\ExampleFileLoader;
use Radiergummi\OpenApi\Tests\Fixtures\PropertyFixtureData;
use RuntimeException;

uses()->group('openapi');

class FileExampleController extends Controller
{
    #[Example(
        name: 'from-file',
        file: 'tests/Fixtures/OpenApi/example_payloads/create_project.json',
    )]
    public function store(PropertyFixtureData $data): JsonResponse
    {
        return new JsonResponse();
    }
}

it('OAPI-022: ExampleFileLoader loads and decodes a JSON file relative to the project root', function (): void {
    $loader = new ExampleFileLoader();
    $data = $loader->load('tests/Fixtures/OpenApi/example_payloads/create_project.json');

    expect($data)
        ->toBeArray()
        ->and($data['name'])->toBe('Aerospace Q1 Sourcing')
        ->and($data['keywords'])->toBe(['aerospace', 'titanium', 'fasteners']);
});

it('OAPI-022: ExampleFileLoader throws when the file does not exist', function (): void {
    $loader = new ExampleFileLoader();

    expect(fn() => $loader->load('tests/Fixtures/OpenApi/example_payloads/nonexistent.json'))
        ->toThrow(RuntimeException::class);
});

it('OAPI-022: ExampleFileLoader caches the result — second call is identical', function (): void {
    $loader = new ExampleFileLoader();

    $first = $loader->load('tests/Fixtures/OpenApi/example_payloads/create_project.json');
    $second = $loader->load('tests/Fixtures/OpenApi/example_payloads/create_project.json');

    expect($second)->toBe($first);
});

it('OAPI-022: #[Example(file:)] emits the file payload in the generated spec', function (): void {
    RouteFacade::post(
        '/oa-p2/file-example',
        [FileExampleController::class, 'store'],
    );

    $spec = generateSpec();

    $requestBody = $spec['paths']['/oa-p2/file-example']['post']['requestBody'] ?? null;

    expect($requestBody)->not->toBeNull();

    $examples = $requestBody['content']['application/json']['examples'] ?? [];

    expect($examples)
        ->toHaveKey('from-file')
        ->and($examples['from-file']['value']['name'])->toBe('Aerospace Q1 Sourcing')
        ->and($examples['from-file']['value']['keywords'])->toBe(['aerospace', 'titanium', 'fasteners']);
});
