<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal;

use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Resolvers\TransformerRefSchemaResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Support\SchemaFromTransformer;
use Radiergummi\OpenApi\Plugins\Fractal\Support\TransformerTransformReader;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Extraction\ModelFactoryExampleReader;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\SingleReturnArrayLiteralFinder;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\DynamicBodyTransformer;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\InferredArticleTransformer;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

#[TransformerField('id', type: 'integer')]
class RefFixtureTransformer {}

class NotATransformer {}

function makeTransformerRefResolver(): TransformerRefSchemaResolver
{
    $registry = new ComponentSchemaRegistry();
    $logger = new NullLogger();

    $transformReader = new TransformerTransformReader(
        returnLiteralFinder: new SingleReturnArrayLiteralFinder(new MethodBodyScanner()),
        modelToSchema: new EloquentModelToSchema(
            registry: $registry,
            jsonSchemaFromType: new JsonSchemaFromType($logger, $registry),
            typeResolver: TypeResolver::create(),
            typeNodeResolver: TypeNodeResolver::create(),
            docBlockParser: DocBlockParser::create(),
            logger: $logger,
            factoryExampleReader: new ModelFactoryExampleReader(seed: 1234, logger: $logger),
        ),
    );

    return new TransformerRefSchemaResolver(
        new SchemaFromTransformer($registry, static fn(): array => [], $transformReader, $logger),
        $transformReader,
    );
}

it('resolves a transformer-shaped class to a components ref', function (): void {
    expect(makeTransformerRefResolver()->resolveRef(RefFixtureTransformer::class))
        ->toBe('#/components/schemas/RefFixtureTransformer');
});

it('returns null for a class with no #[TransformerField]', function (): void {
    expect(makeTransformerRefResolver()->resolveRef(NotATransformer::class))->toBeNull();
});

it('resolves an attribute-free TransformerAbstract subclass with a readable transform()', function (): void {
    expect(makeTransformerRefResolver()->resolveRef(InferredArticleTransformer::class))
        ->toBe('#/components/schemas/InferredArticleTransformer');
});

it('returns null for an attribute-free transformer with a dynamic transform() body', function (): void {
    expect(makeTransformerRefResolver()->resolveRef(DynamicBodyTransformer::class))->toBeNull();
});
