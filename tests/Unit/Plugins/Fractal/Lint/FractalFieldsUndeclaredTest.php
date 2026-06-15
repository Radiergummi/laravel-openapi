<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Lint;

use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalFieldsUndeclared;
use Radiergummi\OpenApi\Plugins\Fractal\Support\TransformerTransformReader;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Extraction\ModelFactoryExampleReader;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\SingleReturnArrayLiteralFinder;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Support\Types\TypeNodeToSchema;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\InferredArticleTransformer;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

uses()->group('openapi', 'plugin:fractal');

class BareFractalTransformer {}

#[TransformerField('id', type: 'integer')]
class PopulatedFractalTransformer {}

class BareFractalController
{
    #[FractalResponse(transformer: BareFractalTransformer::class)]
    public function bare(): void {}

    #[FractalResponse(transformer: PopulatedFractalTransformer::class)]
    public function populated(): void {}

    #[FractalResponse(transformer: InferredArticleTransformer::class)]
    public function inferred(): void {}

    public function unbound(): void {}
}

function fractalFieldsUndeclaredRule(): FractalFieldsUndeclared
{
    $logger = new NullLogger();
    $registry = new ComponentSchemaRegistry();

    return new FractalFieldsUndeclared(new TransformerTransformReader(
        returnLiteralFinder: new SingleReturnArrayLiteralFinder(new MethodBodyScanner()),
        modelToSchema: new EloquentModelToSchema(
            registry: $registry,
            jsonSchemaFromType: new JsonSchemaFromType($logger, $registry),
            typeNodeToSchema: new TypeNodeToSchema(),
            typeResolver: TypeResolver::create(),
            typeNodeResolver: TypeNodeResolver::create(),
            docBlockParser: DocBlockParser::create(),
            logger: $logger,
            factoryExampleReader: new ModelFactoryExampleReader(seed: 1234, logger: $logger),
        ),
    ));
}

it('flags a #[FractalResponse] transformer that declares no #[TransformerField]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(BareFractalController::class, 'bare');

    $rule = fractalFieldsUndeclaredRule();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('fractal.fields-undeclared');
});

it('does not flag a transformer that declares #[TransformerField]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(BareFractalController::class, 'populated');

    $rule = fractalFieldsUndeclaredRule();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});

it('does not flag an attribute-free transformer whose transform() literal is readable', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(BareFractalController::class, 'inferred');

    $rule = fractalFieldsUndeclaredRule();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});

it('does not flag a method without #[FractalResponse]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(BareFractalController::class, 'unbound');

    $rule = fractalFieldsUndeclaredRule();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});
