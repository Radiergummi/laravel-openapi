<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;

uses()->group('openapi');

it('starts empty', function (): void {
    $registry = new OpenApiRegistry();

    expect($registry->requestSchemaResolvers())->toBe([])
        ->and($registry->rules())->toBe([]);
});

it('records additions in declared order', function (): void {
    $registry = new OpenApiRegistry();
    $registry->addRequestSchemaResolver('ResolverA');
    $registry->addRequestSchemaResolver('ResolverB');
    $registry->addRule('RuleOne');
    $registry->addRule('RuleTwo');

    expect($registry->requestSchemaResolvers())->toBe(['ResolverA', 'ResolverB'])
        ->and($registry->rules())->toBe(['RuleOne', 'RuleTwo']);
});

it('deduplicates a class registered twice', function (): void {
    $registry = new OpenApiRegistry();
    $registry->addRule('RuleOne');
    $registry->addRule('RuleOne');

    expect($registry->rules())->toBe(['RuleOne']);
});
