<?php

declare(strict_types=1);

use OpenApi\Generator;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Attributes\PathParam;
use Radiergummi\OpenApi\Support\Extraction\UriParametersExtractor;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\Routing\UriParameterDescriptor;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeIdentifier;

uses()->group('routing', 'openapi');

function stringDescriptor(string $name, bool $optional = false): UriParameterDescriptor
{
    return new UriParameterDescriptor(
        name: $name,
        type: Type::builtin(TypeIdentifier::STRING),
        optional: $optional,
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

it('always emits required:true for optional path parameters per OAS 3.x §4.8.12.1', function (): void {
    [$parameter] = $this->extractor->extract([[stringDescriptor('path', optional: true), null]]);

    expect($parameter->required)->toBeTrue()
        ->and($parameter->description)
        ->toBe('Optional in URL — the segment may be omitted when calling this route.');
});

it('appends the optional-in-URL note to an existing #[PathParam] description', function (): void {
    $param = reflectFunctionParameter(
        static function (
            #[PathParam(description: 'The trailing segment.')]
            ?string $path,
        ): void {},
        'path',
    );

    [$parameter] = $this->extractor->extract([[stringDescriptor('path', optional: true), $param]]);

    expect($parameter->required)->toBeTrue()
        ->and($parameter->description)->toBe(
            'The trailing segment. Optional in URL — the segment may be omitted when calling this route.',
        );
});
