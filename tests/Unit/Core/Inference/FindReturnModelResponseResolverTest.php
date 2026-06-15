<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Inference;

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\FindReturnModelResponseResolver;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Extraction\ModelFactoryExampleReader;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Support\Types\TypeNodeToSchema;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Models\User;
use ReflectionClass;
use ReflectionMethod;
use Stringable;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

uses()->group('openapi');

// region Fixtures

/** A non-Model class exposing a `find()` so the whitelist passes but the Model guard rejects it. */
class FindReturnNonModel
{
    public static function find(mixed $id): mixed
    {
        return null;
    }
}

/**
 * Parse-only fixture; actions are never invoked. Each body exercises one call shape.
 */
class FindReturnFixtureController
{
    public function find(string $id): mixed
    {
        return User::find($id);
    }

    public function findOrFail(string $id): mixed
    {
        return User::findOrFail($id);
    }

    public function firstOrFail(): mixed
    {
        return User::firstOrFail();
    }

    public function typedModelReturn(string $id): ?User
    {
        return User::find($id);
    }

    public function dynamicClass(string $class, string $id): mixed
    {
        return $class::find($id);
    }

    public function wrapped(string $id): mixed
    {
        return response()->json(User::find($id));
    }

    public function assignedThenReturned(string $id): mixed
    {
        $user = User::find($id);

        return $user;
    }

    public function conditionalOnly(string $id, bool $flag): mixed
    {
        if ($flag) {
            return User::find($id);
        }

        return null;
    }

    public function nonModelClass(string $id): mixed
    {
        return FindReturnNonModel::find($id);
    }

    public function nonWhitelistMethod(): mixed
    {
        return User::all();
    }

    public function article(string $id): mixed
    {
        return Article::find($id);
    }

    public function ternary(string $id, bool $flag): mixed
    {
        return $flag ? User::find($id) : null;
    }

    public function arrayWrapped(string $id): mixed
    {
        return [User::find($id)];
    }
}

// endregion

// region Helpers

function findReturnResolver(?LoggerInterface $logger = null): FindReturnModelResponseResolver
{
    $logger ??= new NullLogger();
    $registry = new ComponentSchemaRegistry();

    $modelToSchema = new EloquentModelToSchema(
        registry: $registry,
        jsonSchemaFromType: new JsonSchemaFromType($logger, $registry),
        typeNodeToSchema: new TypeNodeToSchema(),
        typeResolver: TypeResolver::create(),
        typeNodeResolver: TypeNodeResolver::create(),
        docBlockParser: DocBlockParser::create(),
        logger: $logger,
        factoryExampleReader: new ModelFactoryExampleReader(seed: 1234, logger: $logger),
    );

    return new FindReturnModelResponseResolver(
        new MethodBodyScanner(),
        $modelToSchema,
        $registry,
        $logger,
    );
}

function findReturnDescriptor(string $method): ActionDescriptor
{
    /** @var class-string $controller */
    $controller = FindReturnFixtureController::class;

    return new ActionDescriptor(
        route: new Route(['GET'], '/test', static fn() => null),
        controller: new ReflectionClass($controller),
        method: new ReflectionMethod($controller, $method),
        summary: null,
        description: null,
    );
}

/**
 * @return AbstractLogger&object{records: list<array{level: mixed, message: string}>}
 */
function findReturnRecordingLogger(): AbstractLogger
{
    return new class () extends AbstractLogger {
        /** @var list<array{level: mixed, message: string}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message];
        }
    };
}

function findReturnRef(OA\Response $response): string
{
    /** @var array<string, mixed> $serialized */
    $serialized = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($serialized['content'])->toHaveKey('application/json');

    return $serialized['content']['application/json']['schema']['$ref'];
}

// endregion

// region Matched shapes

it('resolves a returned find() to a 200 model $ref', function (): void {
    $response = findReturnResolver()->resolvePrimaryResponse(findReturnDescriptor('find'));

    expect($response)->not->toBeNull()
        ->and($response->response)->toBe('200')
        ->and($response->description)->toBe('OK')
        ->and(findReturnRef($response))->toContain('User');
});

it('resolves a returned findOrFail() to a 200 model $ref', function (): void {
    $response = findReturnResolver()->resolvePrimaryResponse(findReturnDescriptor('findOrFail'));

    expect($response)->not->toBeNull()
        ->and(findReturnRef($response))->toContain('User');
});

it('resolves a returned firstOrFail() to a 200 model $ref', function (): void {
    $response = findReturnResolver()->resolvePrimaryResponse(findReturnDescriptor('firstOrFail'));

    expect($response)->not->toBeNull()
        ->and(findReturnRef($response))->toContain('User');
});

// endregion

// region Deferral and degradation

it('defers a typed Model return to the reflection resolver', function (): void {
    expect(findReturnResolver()->resolvePrimaryResponse(findReturnDescriptor('typedModelReturn')))->toBeNull();
});

it('refuses a dynamic class lookup with a notice', function (): void {
    $logger = findReturnRecordingLogger();

    expect(findReturnResolver($logger)->resolvePrimaryResponse(findReturnDescriptor('dynamicClass')))->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('notice');
});

it('refuses a wrapped lookup with a notice', function (): void {
    $logger = findReturnRecordingLogger();

    expect(findReturnResolver($logger)->resolvePrimaryResponse(findReturnDescriptor('wrapped')))->toBeNull()
        ->and($logger->records)->toHaveCount(1);
});

it('refuses a variable-assigned lookup (no dataflow) with a notice', function (): void {
    $logger = findReturnRecordingLogger();

    expect(findReturnResolver($logger)->resolvePrimaryResponse(findReturnDescriptor('assignedThenReturned')))->toBeNull()
        ->and($logger->records)->toHaveCount(1);
});

it('refuses a conditional-only lookup with a notice', function (): void {
    $logger = findReturnRecordingLogger();

    expect(findReturnResolver($logger)->resolvePrimaryResponse(findReturnDescriptor('conditionalOnly')))->toBeNull()
        ->and($logger->records)->toHaveCount(1);
});

it('refuses a ternary-returned lookup with a notice', function (): void {
    $logger = findReturnRecordingLogger();

    expect(findReturnResolver($logger)->resolvePrimaryResponse(findReturnDescriptor('ternary')))->toBeNull()
        ->and($logger->records)->toHaveCount(1);
});

it('refuses an array-wrapped lookup with a notice', function (): void {
    $logger = findReturnRecordingLogger();

    expect(findReturnResolver($logger)->resolvePrimaryResponse(findReturnDescriptor('arrayWrapped')))->toBeNull()
        ->and($logger->records)->toHaveCount(1);
});

it('returns null for a non-Model class lookup', function (): void {
    expect(findReturnResolver()->resolvePrimaryResponse(findReturnDescriptor('nonModelClass')))->toBeNull();
});

it('returns null for a non-whitelist method', function (): void {
    expect(findReturnResolver()->resolvePrimaryResponse(findReturnDescriptor('nonWhitelistMethod')))->toBeNull();
});

it('returns null when the descriptor has no method', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/test', static fn() => null),
        controller: null,
        method: null,
        summary: null,
        description: null,
    );

    expect(findReturnResolver()->resolvePrimaryResponse($descriptor))->toBeNull();
});

// endregion

it('keeps a non-Model class lookup silent (no notice)', function (): void {
    $logger = findReturnRecordingLogger();

    findReturnResolver($logger)->resolvePrimaryResponse(findReturnDescriptor('nonModelClass'));

    // nonModelClass IS a whitelisted find() that is directly returned, so it resolves the class
    // then fails the Model guard — silent (resolveModelClass returns null without a notice).
    expect($logger->records)->toBeEmpty();
});

it('builds the schema from a real model fixture', function (): void {
    // Article carries metadata, proving the reader actually builds a component (not just a name).
    $response = findReturnResolver()->resolvePrimaryResponse(findReturnDescriptor('article'));

    expect($response)->not->toBeNull()
        ->and(findReturnRef($response))->toContain('Article');
});
