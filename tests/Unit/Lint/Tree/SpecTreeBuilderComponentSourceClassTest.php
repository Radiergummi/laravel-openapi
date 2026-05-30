<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeBuilder;

uses()->group('openapi', 'lint');

it('populates ComponentSchemaNode::sourceClass from the supplied component class map', function (): void {
    $context = new Context();
    $schema = new OA\Schema([
        'schema' => 'Some',
        'properties' => [new OA\Property(['property' => 'name', 'type' => 'string', '_context' => $context])],
        '_context' => $context,
    ]);
    $document = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 't', 'version' => '1', '_context' => $context]),
        'components' => new OA\Components(['schemas' => [$schema], '_context' => $context]),
        '_context' => $context,
    ]);

    $builder = new SpecTreeBuilder(componentClassMap: ['Some' => 'App\\Some']);
    $api = $builder->build($document, []);

    expect($api->components)->toHaveCount(1)
        ->and($api->components[0]->sourceClass)->toBe('App\\Some');
});

it('leaves ComponentSchemaNode::sourceClass null when no class is mapped for the key', function (): void {
    $context = new Context();
    $schema = new OA\Schema([
        'schema' => 'Unowned',
        'properties' => [],
        '_context' => $context,
    ]);
    $document = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 't', 'version' => '1', '_context' => $context]),
        'components' => new OA\Components(['schemas' => [$schema], '_context' => $context]),
        '_context' => $context,
    ]);

    $builder = new SpecTreeBuilder();
    $api = $builder->build($document, []);

    expect($api->components)->toHaveCount(1)
        ->and($api->components[0]->sourceClass)->toBeNull();
});
