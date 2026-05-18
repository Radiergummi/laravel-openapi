<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Generator;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Core\Attributes\PathParam;
use Radiergummi\OpenApi\Core\Extractors\UriParametersExtractor;
use Radiergummi\OpenApi\Core\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Core\Routing\UriParameterDescriptor;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeIdentifier;

uses()->group('routing', 'openapi');

function stringDescriptor(string $name): UriParameterDescriptor
{
    return new UriParameterDescriptor(
        name: $name,
        type: Type::builtin(TypeIdentifier::STRING),
        optional: false,
        whereConstraint: null,
        whereKind: null,
        modelClass: null,
        routeKeyName: null,
        enumCases: null,
    );
}

beforeEach(function (): void {
    $this->extractor = new UriParametersExtractor(new JsonSchemaFromType(new NullLogger()));
});

it('enriches a path parameter with description and example from #[PathParam]', function (): void {
    $param = reflectFunctionParameter(
        static function (
            #[PathParam(description: 'The company to retrieve.', example: '01HFP-EXAMPLE')]
            string $company,
        ): void {},
        'company',
    );

    [$parameter] = $this->extractor->extract([[stringDescriptor('company'), $param]]);

    expect($parameter->description)->toBe('The company to retrieve.')
        ->and($parameter->schema->example)->toBe('01HFP-EXAMPLE');
});

it('omits the description when no #[PathParam] attribute is present', function (): void {
    $param = reflectFunctionParameter(static function (string $company): void {}, 'company');

    [$parameter] = $this->extractor->extract([[stringDescriptor('company'), $param]]);

    expect($parameter->description)->toBe(Generator::UNDEFINED)
        ->and($parameter->name)->toBe('company')
        ->and($parameter->required)->toBeTrue();
});

it('tolerates a missing reflection parameter', function (): void {
    [$parameter] = $this->extractor->extract([[stringDescriptor('company'), null]]);

    expect($parameter->name)->toBe('company')
        ->and($parameter->description)->toBe(Generator::UNDEFINED);
});
