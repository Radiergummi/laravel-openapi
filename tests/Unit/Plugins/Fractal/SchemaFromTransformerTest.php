<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal;

use Closure;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
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
use Radiergummi\OpenApi\Support\Types\TypeNodeToSchema;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\DeclaredAndInferredTransformer;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\DynamicBodyTransformer;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\InferredArticleTransformer;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function array_find;
use function str_contains;

function makeSchemaFromTransformer(
    ComponentSchemaRegistry $registry,
    ?Closure $refSchemaResolvers = null,
    ?LoggerInterface $logger = null,
): SchemaFromTransformer {
    $logger ??= new NullLogger();

    return new SchemaFromTransformer(
        registry: $registry,
        refSchemaResolvers: $refSchemaResolvers ?? static fn(): array => [],
        transformReader: new TransformerTransformReader(
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
        ),
        logger: $logger,
    );
}

#[TransformerField('id', type: 'integer')]
#[TransformerField('title', type: 'string', maxLength: 120)]
#[TransformerInclude('author', transformer: SchemaAuthorTransformer::class, default: true)]
class SchemaBookTransformer {}

#[TransformerField('name', type: 'string')]
class SchemaAuthorTransformer {}

class NotAFractalTransformer {}

#[TransformerField('relatedData', type: NotAFractalTransformer::class)]
class SchemaWithResolvedRefTransformer {}

/** @return array<string, OA\Property> */
function transformerPropertiesByName(OA\Schema $schema): array
{
    $out = [];

    foreach ($schema->properties as $property) {
        $out[$property->property] = $property;
    }

    return $out;
}

it('builds an object schema from transformer attributes', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = makeSchemaFromTransformer($registry)->build(SchemaBookTransformer::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);
    $props = transformerPropertiesByName($schema);

    expect($schema->type)->toBe('object')
        ->and($props)->toHaveKeys(['id', 'title', 'author']);
});

it('applies scalar descriptor fields onto the property', function (): void {
    $registry = new ComponentSchemaRegistry();
    makeSchemaFromTransformer($registry)->build(SchemaBookTransformer::class);

    $book = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === 'SchemaBookTransformer');

    expect(transformerPropertiesByName($book)['title']->maxLength)->toBe(120);
});

it('emits an include as a $ref and registers the included transformer', function (): void {
    $registry = new ComponentSchemaRegistry();
    makeSchemaFromTransformer($registry)->build(SchemaBookTransformer::class);

    $keys = array_map(static fn(OA\Schema $s): string => $s->schema, $registry->all());
    expect($keys)->toContain('SchemaAuthorTransformer');
});

it('marks default includes as required and non-default as optional', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = makeSchemaFromTransformer($registry)->build(SchemaBookTransformer::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);

    expect($schema->required)->toContain('author');
});

it('exposes buildRef returning a qualified components ref', function (): void {
    $registry = new ComponentSchemaRegistry();
    $ref = makeSchemaFromTransformer($registry)->buildRef(SchemaBookTransformer::class);

    expect($ref)->toBe('#/components/schemas/SchemaBookTransformer');
});

it('composes inferred transform() fields after declared attributes, attribute winning per field', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = makeSchemaFromTransformer($registry)->build(DeclaredAndInferredTransformer::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);
    $props = transformerPropertiesByName($schema);

    expect(array_keys($props))->toBe(['id', 'title'])
        ->and($props['id']->format)->toBe('uuid')
        ->and($props['title']->type)->toBe('string')
        ->and($schema->required)->toBe(['id', 'title']);
});

it('builds an attribute-free transformer schema entirely from the transform() literal', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = makeSchemaFromTransformer($registry)->build(InferredArticleTransformer::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);
    $props = transformerPropertiesByName($schema);

    expect($props)->toHaveKeys(['id', 'title', 'word_count', 'kind', 'permalink'])
        ->and($props['word_count']->type)->toBe('integer')
        ->and($schema->required)->toContain('permalink');
});

it('lists nested unreadable values by key path in the summarising notice', function (): void {
    $registry = new ComponentSchemaRegistry();
    $logger = recordingLogger();

    makeSchemaFromTransformer($registry, logger: $logger)->build(InferredArticleTransformer::class);

    $notice = array_find(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], InferredArticleTransformer::class),
    );

    expect($notice)->not->toBeNull()
        ->and($notice['message'] ?? '')->toContain('permalink')
        ->toContain('flags.rating');
});

it('degrades a dynamic transform() body to the attribute-declared shape', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = makeSchemaFromTransformer($registry)->build(DynamicBodyTransformer::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);

    expect($schema->properties)->toBe([]);
});

it('resolves non-transformer class refs via injected RefSchemaResolver', function (): void {
    $registry = new ComponentSchemaRegistry();
    $customResolver = new class () implements RefSchemaResolver {
        public function canResolve(string $class): bool
        {
            return $class === NotAFractalTransformer::class;
        }

        public function resolveRef(string $class): ?string
        {
            return $this->canResolve($class)
                ? '#/components/schemas/CustomRef'
                : null;
        }
    };

    makeSchemaFromTransformer($registry, static fn(): array => [$customResolver])->build(SchemaWithResolvedRefTransformer::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === 'SchemaWithResolvedRefTransformer');
    $props = transformerPropertiesByName($schema);

    expect($props['relatedData']->ref)->toBe('#/components/schemas/CustomRef');
});
