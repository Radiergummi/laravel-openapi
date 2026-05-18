<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Extractors\FormRequestRequestSchemaResolver;
use Radiergummi\OpenApi\Core\Lint\Rules\SpecInvalid;
use Radiergummi\OpenApi\Core\Registry\CoreRegistration;
use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;

uses()->group('openapi');

it('registers the FormRequest request schema resolver', function (): void {
    $registry = new OpenApiRegistry();
    CoreRegistration::register($registry);

    expect($registry->requestSchemaResolvers())
        ->toContain(FormRequestRequestSchemaResolver::class);
});

it('registers all core lint rules', function (): void {
    $registry = new OpenApiRegistry();
    CoreRegistration::register($registry);

    expect($registry->rules())
        ->toHaveCount(count(CoreRegistration::RULES))
        ->toContain(SpecInvalid::class);
});
