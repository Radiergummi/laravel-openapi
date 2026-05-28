<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\Example;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Attributes\ResponseExample;

/**
 * Fixture controller exercising the {@see Example} and {@see ResponseExample} attributes
 * end-to-end. Registered ad-hoc by tests; not auto-discovered by `route:sync` or production
 * routing.
 */
final class ExampleFixtureController extends Controller
{
    #[Example(
        name: 'minimal',
        value: ['name' => 'Aerospace Q1'],
        summary: 'Bare-minimum payload',
    )]
    #[Example(
        name: 'full',
        value: ['name' => 'Aerospace Q1', 'callbackUrl' => 'https://hooks.example.com'],
    )]
    #[Response(status: 422, description: 'Validation failed')]
    #[ResponseExample(
        status: 200,
        name: 'happy-path',
        value: ['data' => ['id' => 'abc', 'type' => 'project']],
    )]
    #[ResponseExample(
        status: 422,
        name: 'missing-name',
        value: ['errors' => [['detail' => 'The name field is required.']]],
    )]
    public function create(PropertyFixtureData $data): array
    {
        return ['data' => ['id' => 'fixture', 'type' => 'project']];
    }
}
