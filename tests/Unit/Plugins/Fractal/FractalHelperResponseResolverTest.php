<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route;
use League\Fractal\Manager;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use League\Fractal\Serializer\ArraySerializer;
use League\Fractal\Serializer\JsonApiSerializer;
use OpenApi\Annotations as OA;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Resolvers\FractalHelperResponseResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Support\FractalEnvelopeFactory;
use Radiergummi\OpenApi\Plugins\Fractal\Support\SchemaFromTransformer;
use Radiergummi\OpenApi\Plugins\Fractal\Support\TransformerTransformReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Extraction\ModelFactoryExampleReader;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\SingleReturnArrayLiteralFinder;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Support\Types\TypeNodeToSchema;
use Radiergummi\OpenApi\Tests\Fixtures\Fractal\CustomSerializer;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\EmptyTransformer;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\InferredArticleTransformer;
use ReflectionClass;
use ReflectionMethod;
use Stringable;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function array_any;
use function fractal;
use function is_array;
use function str_contains;

uses()->group('openapi', 'plugin:fractal');

// region Fixtures

/**
 * An unrelated service exposing `item()` / `collection()` — proves the binding keys off the
 * `fractal()` / facade chain root, not the method names.
 */
class HelperResolverFakeService
{
    public function item(mixed $data, mixed $transformer): mixed
    {
        return $data;
    }

    public function collection(mixed $data, mixed $transformer): mixed
    {
        return $data;
    }
}

/**
 * Parse-only fixture; actions are never invoked. Each body exercises one call shape.
 */
class HelperResolverFixtureController
{
    public function __construct(
        private readonly Manager $manager = new Manager(),
        private readonly HelperResolverFakeService $service = new HelperResolverFakeService(),
    ) {}

    public function helperItem(): JsonResponse
    {
        return fractal()->item(new Article(), new InferredArticleTransformer())->respond();
    }

    public function helperCollection(): JsonResponse
    {
        return fractal()->collection([], new InferredArticleTransformer())->respond();
    }

    public function classConstTransformer(): JsonResponse
    {
        return fractal()->item(new Article(), InferredArticleTransformer::class)->respond();
    }

    public function arraySerializer(): JsonResponse
    {
        return fractal()
            ->collection([], new InferredArticleTransformer())
            ->serializeWith(new ArraySerializer())
            ->respond();
    }

    public function jsonApiSerializer(): JsonResponse
    {
        return fractal()
            ->item(new Article(), new InferredArticleTransformer())
            ->serializeWith(new JsonApiSerializer())
            ->respond();
    }

    public function managerItem(): JsonResponse
    {
        $resource = new Item(new Article(), new InferredArticleTransformer());

        return new JsonResponse($this->manager->createData($resource)->toArray());
    }

    public function managerCollection(): JsonResponse
    {
        $resource = new Collection([], new InferredArticleTransformer());

        return new JsonResponse($this->manager->createData($resource)->toArray());
    }

    /** Bare two-arg helper: item vs collection is not knowable. */
    public function bareTwoArgument(): JsonResponse
    {
        return fractal(new Article(), new InferredArticleTransformer())->respond();
    }

    public function variableTransformer(): JsonResponse
    {
        $transformer = new InferredArticleTransformer();

        return fractal()->item(new Article(), $transformer)->respond();
    }

    public function emptyTransformer(): JsonResponse
    {
        return fractal()->item(new Article(), new EmptyTransformer())->respond();
    }

    public function unknownSerializer(): JsonResponse
    {
        return fractal()
            ->item(new Article(), new InferredArticleTransformer())
            ->serializeWith(new CustomSerializer())
            ->respond();
    }

    #[FractalResponse(transformer: InferredArticleTransformer::class)]
    public function attributed(): JsonResponse
    {
        return fractal()->item(new Article(), new InferredArticleTransformer())->respond();
    }

    public function typedModelReturn(): Article
    {
        return new Article();
    }

    public function serviceItem(): mixed
    {
        return $this->service->item(new Article(), new InferredArticleTransformer());
    }

    public function serviceCollection(): mixed
    {
        return $this->service->collection([], new InferredArticleTransformer());
    }

    public function plain(): mixed
    {
        return ['ok' => true];
    }
}

// endregion

// region Helpers

function helperResolver(?LoggerInterface $logger = null): FractalHelperResponseResolver
{
    $logger ??= new NullLogger();
    $registry = new ComponentSchemaRegistry();

    $transformReader = new TransformerTransformReader(
        returnLiteralFinder: new SingleReturnArrayLiteralFinder(new MethodBodyScanner()),
        modelToSchema: new EloquentModelToSchema(
            registry: $registry,
            jsonSchemaFromType: new JsonSchemaFromType($logger, $registry),
            typeNodeToSchema: new TypeNodeToSchema(),
            typeResolver: TypeResolver::create(),
            typeNodeResolver: TypeNodeResolver::create(),
            docBlockParser: DocBlockParser::create(),
            factoryExampleReader: new ModelFactoryExampleReader(seed: 1234, logger: $logger),
            logger: $logger,
        ),
    );

    $schemaFromTransformer = new SchemaFromTransformer(
        registry: $registry,
        refSchemaResolvers: static fn(): array => [],
        transformReader: $transformReader,
        logger: $logger,
    );

    return new FractalHelperResponseResolver(
        new MethodBodyScanner(),
        $transformReader,
        $schemaFromTransformer,
        new FractalEnvelopeFactory(),
        $logger,
    );
}

function helperDescriptor(string $method): ActionDescriptor
{
    /** @var class-string $controller */
    $controller = HelperResolverFixtureController::class;

    return new ActionDescriptor(
        route: new Route(['GET'], '/test', static fn() => null),
        controller: new ReflectionClass($controller),
        method: new ReflectionMethod($controller, $method),
        summary: null,
        description: null,
    );
}

/**
 * @return AbstractLogger&object{records: list<array{message: string}>}
 */
function helperRecordingLogger(): AbstractLogger
{
    return new class () extends AbstractLogger {
        /** @var list<array{message: string}> */
        public array $records = [];

        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['message' => (string) $message];
        }
    };
}

/**
 * The `OA\Schema` carried for the given media type by an `OA\Response`, or null when the response
 * is null or carries no content for that media type.
 */
function helperContentSchema(?OA\Response $response, string $mediaType = 'application/json'): mixed
{
    $content = $response?->content;

    if (!is_array($content)) {
        return null;
    }

    foreach ($content as $entry) {
        if ($entry instanceof OA\MediaType && $entry->mediaType === $mediaType) {
            return $entry->schema;
        }
    }

    return null;
}

// endregion

it('binds fractal()->item() to the single envelope', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('helperItem'));

    $schema = helperContentSchema($response);

    expect($schema)->not->toBeNull()
        ->and($schema->properties[0]->property)->toBe('data');
});

it('binds fractal()->collection() to the collection envelope', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('helperCollection'));

    $schema = helperContentSchema($response);

    expect($schema->properties[0]->property)->toBe('data')
        ->and($schema->properties[0]->type)->toBe('array');
});

it('reads the transformer from a ::class argument', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('classConstTransformer'));

    expect(helperContentSchema($response))->not->toBeNull();
});

it('binds an injected Manager + new Item() to the single envelope', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('managerItem'));

    $schema = helperContentSchema($response);

    expect($schema->properties[0]->property)->toBe('data')
        ->and($schema->properties[0]->ref)->toBe('#/components/schemas/InferredArticleTransformer');
});

it('binds an injected Manager + new Collection() to the collection envelope', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('managerCollection'));

    $schema = helperContentSchema($response);

    expect($schema->properties[0]->property)->toBe('data')
        ->and($schema->properties[0]->type)->toBe('array');
});

it('maps serializeWith(ArraySerializer) onto the ArraySerializer envelope', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('arraySerializer'));

    $schema = helperContentSchema($response);

    // ArraySerializer collections are a top-level array, not a {data: [...]} wrapper.
    expect($schema->type)->toBe('array');
});

it('maps serializeWith(JsonApiSerializer) onto the JsonApi envelope and media type', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('jsonApiSerializer'));

    expect(helperContentSchema($response, 'application/vnd.api+json'))->not->toBeNull()
        ->and(helperContentSchema($response))->toBeNull();
});

it('refuses the bare two-argument helper form', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('bareTwoArgument'));

    expect($response)->toBeNull();
});

it('refuses a variable transformer argument', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('variableTransformer'));

    expect($response)->toBeNull();
});

it('refuses a transformer with no documentable fields, noting it', function (): void {
    $logger = helperRecordingLogger();
    $response = helperResolver($logger)->resolvePrimaryResponse(helperDescriptor('emptyTransformer'));

    expect($response)->toBeNull();

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], EmptyTransformer::class)
            && str_contains($record['message'], 'yields no documentable fields'),
    );

    expect($noted)->toBeTrue();
});

it('refuses an unrecognised serializer, noting it', function (): void {
    $logger = helperRecordingLogger();
    $response = helperResolver($logger)->resolvePrimaryResponse(helperDescriptor('unknownSerializer'));

    expect($response)->toBeNull();

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'not one of the modelled cases'),
    );

    expect($noted)->toBeTrue();
});

it('never scans an action carrying #[FractalResponse]', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('attributed'));

    expect($response)->toBeNull();
});

it('refuses a named non-HTTP return type', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('typedModelReturn'));

    expect($response)->toBeNull();
});

it('does not fire on item() called on a non-Fractal receiver', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('serviceItem'));

    expect($response)->toBeNull();
});

it('does not fire on collection() called on a non-Fractal receiver', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('serviceCollection'));

    expect($response)->toBeNull();
});

it('stays silent for a method without any whitelisted call shape', function (): void {
    $response = helperResolver()->resolvePrimaryResponse(helperDescriptor('plain'));

    expect($response)->toBeNull();
});
