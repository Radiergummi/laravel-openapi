<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Routing\Controller;

/**
 * Fixture controller exercising the {@see \Radiergummi\OpenApi\Core\Attributes\Example} and
 * {@see \Radiergummi\OpenApi\Core\Attributes\ResponseExample} attributes end-to-end. Registered ad-hoc by
 * tests; not auto-discovered by `route:sync` or production routing.
 */
final class ExampleFixtureController extends Controller
{
    #[\Radiergummi\OpenApi\Core\Attributes\Example(
        name: 'minimal',
        value: ['name' => 'Aerospace Q1'],
        summary: 'Bare-minimum payload',
    )]
    #[\Radiergummi\OpenApi\Core\Attributes\Example(
        name: 'full',
        value: ['name' => 'Aerospace Q1', 'callbackUrl' => 'https://hooks.example.com'],
    )]
    #[\Radiergummi\OpenApi\Core\Attributes\Response(status: 422, description: 'Validation failed')]
    #[\Radiergummi\OpenApi\Core\Attributes\ResponseExample(
        status: 200,
        name: 'happy-path',
        value: ['data' => ['id' => 'abc', 'type' => 'project']],
    )]
    #[\Radiergummi\OpenApi\Core\Attributes\ResponseExample(
        status: 422,
        name: 'missing-name',
        value: ['errors' => [['detail' => 'The name field is required.']]],
    )]
    public function create(PropertyFixtureData $data): array
    {
        return ['data' => ['id' => 'fixture', 'type' => 'project']];
    }
}
