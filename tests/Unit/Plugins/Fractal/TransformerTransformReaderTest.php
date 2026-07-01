<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal;

use OpenApi\Generator;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Plugins\Fractal\Support\InferredTransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Support\TransformerTransformReader;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Extraction\ModelFactoryExampleReader;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\SingleReturnArrayLiteralFinder;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\DynamicBodyTransformer;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\DynamicKeyTransformer;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\InferredArticleTransformer;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\SpreadTransformer;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\UntypedParameterTransformer;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\VariableReturnTransformer;
use RuntimeException;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

uses()->group('openapi', 'plugin:fractal');

function transformReader(): TransformerTransformReader
{
    $logger = new NullLogger();
    $registry = new ComponentSchemaRegistry();

    return new TransformerTransformReader(
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
}

/**
 * @param class-string $transformerClass
 *
 * @return list<InferredTransformerField>
 */
function readTransformerFields(string $transformerClass): array
{
    $fields = transformReader()->read($transformerClass);

    if ($fields === null) {
        throw new RuntimeException("transform() of {$transformerClass} was refused");
    }

    return $fields;
}

/**
 * @param list<InferredTransformerField> $fields
 */
function transformerField(array $fields, string $name): InferredTransformerField
{
    foreach ($fields as $field) {
        if ($field->name === $name) {
            return $field;
        }
    }

    throw new RuntimeException("No inferred field named {$name}");
}

it('reads the literal keys of a single-return transform() in literal order', function (): void {
    $fields = readTransformerFields(InferredArticleTransformer::class);

    expect(array_map(static fn(InferredTransformerField $field): string => $field->name, $fields))
        ->toBe(
            ['id', 'title', 'published_at', 'word_count', 'price', 'archived', 'tags', 'kind', 'flags', 'permalink'],
        );
});

it('resolves $model->field values against the typed transform() parameter', function (): void {
    $fields = readTransformerFields(InferredArticleTransformer::class);

    expect(transformerField($fields, 'id')->unconstrainedPaths)
        ->toBe([])
        ->and(transformerField($fields, 'title')->property->type)->toBe('string')
        ->and(transformerField($fields, 'published_at')->property->format)->toBe('date-time');
});

it('types cast values by the cast', function (): void {
    $fields = readTransformerFields(InferredArticleTransformer::class);

    expect(transformerField($fields, 'word_count')->property->type)
        ->toBe('integer')
        ->and(transformerField($fields, 'price')->property->type)->toBe('number')
        ->and(transformerField($fields, 'archived')->property->type)->toBe('boolean');
});

it('emits unconstrained items alongside an (array) cast', function (): void {
    $fields = readTransformerFields(InferredArticleTransformer::class);

    $tags = transformerField($fields, 'tags');

    // swagger-php's Analysis::validate() rejects `type: array` without `items`; an `(array)`
    // cast guarantees an array of unknown items, so the honest schema is `items: {}`.
    expect($tags->property->type)
        ->toBe('array')
        ->and(Generator::isDefault($tags->property->items))->toBeFalse();
});

it('types literal scalar and nested literal array values', function (): void {
    $fields = readTransformerFields(InferredArticleTransformer::class);

    $flags = transformerField($fields, 'flags');

    expect(transformerField($fields, 'kind')->property->type)
        ->toBe('string')
        ->and($flags->property->type)->toBe('object')
        ->and($flags->property->properties[0]->property)->toBe('featured')
        ->and($flags->property->properties[0]->type)->toBe('boolean')
        // Casts resolve only at the top level of values; the nested one degrades to an
        // unconstrained inner property, reported by its key path.
        ->and($flags->unconstrainedPaths)->toBe(['flags.rating']);
});

it('keeps an unresolvable value as an unconstrained property', function (): void {
    $fields = readTransformerFields(InferredArticleTransformer::class);

    $permalink = transformerField($fields, 'permalink');

    expect($permalink->unconstrainedPaths)
        ->toBe(['permalink'])
        ->and(Generator::isDefault($permalink->property->type))->toBeTrue();
});

it('keeps model fetches unconstrained when the parameter carries no model type', function (): void {
    $fields = readTransformerFields(UntypedParameterTransformer::class);

    expect(transformerField($fields, 'id')->unconstrainedPaths)
        ->toBe(['id'])
        ->and(transformerField($fields, 'kind')->property->type)->toBe('string');
});

it('reads a transform() that assigns the array to a variable and returns it', function (): void {
    $fields = readTransformerFields(VariableReturnTransformer::class);

    expect(array_map(static fn(InferredTransformerField $field): string => $field->name, $fields))
        ->toBe(['id', 'title'])
        ->and(transformerField($fields, 'title')->property->type)->toBe('string');
});

it('refuses a dynamic transform() body', function (): void {
    expect(transformReader()->read(DynamicBodyTransformer::class))->toBeNull();
});

it('refuses a literal with a dynamic key', function (): void {
    expect(transformReader()->read(DynamicKeyTransformer::class))->toBeNull();
});

it('refuses a literal with a spread entry', function (): void {
    expect(transformReader()->read(SpreadTransformer::class))->toBeNull();
});

it('refuses a class without a transform() method', function (): void {
    expect(transformReader()->read(Article::class))
        ->toBeNull()
        ->and(transformReader()->declaresTransform(Article::class))->toBeFalse();
});

it('recognises league/fractal transformer subclasses', function (): void {
    $reader = transformReader();

    expect($reader->isTransformerSubclass(InferredArticleTransformer::class))
        ->toBeTrue()
        ->and($reader->isTransformerSubclass(Article::class))->toBeFalse();
});
