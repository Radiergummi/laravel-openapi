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

    public function findPastTenStatements(string $id): mixed
    {
        $s1 = 1;
        $s2 = 2;
        $s3 = 3;
        $s4 = 4;
        $s5 = 5;
        $s6 = 6;
        $s7 = 7;
        $s8 = 8;
        $s9 = 9;
        $s10 = 10;
        $s11 = 11;
        $s12 = 12;

        return User::find($id);
    }

    public function findBeyondBackstop(string $id): mixed
    {
        $s1 = 1;
        $s2 = 2;
        $s3 = 3;
        $s4 = 4;
        $s5 = 5;
        $s6 = 6;
        $s7 = 7;
        $s8 = 8;
        $s9 = 9;
        $s10 = 10;
        $s11 = 11;
        $s12 = 12;
        $s13 = 13;
        $s14 = 14;
        $s15 = 15;
        $s16 = 16;
        $s17 = 17;
        $s18 = 18;
        $s19 = 19;
        $s20 = 20;
        $s21 = 21;
        $s22 = 22;
        $s23 = 23;
        $s24 = 24;
        $s25 = 25;
        $s26 = 26;
        $s27 = 27;
        $s28 = 28;
        $s29 = 29;
        $s30 = 30;
        $s31 = 31;
        $s32 = 32;
        $s33 = 33;
        $s34 = 34;
        $s35 = 35;
        $s36 = 36;
        $s37 = 37;
        $s38 = 38;
        $s39 = 39;
        $s40 = 40;
        $s41 = 41;
        $s42 = 42;
        $s43 = 43;
        $s44 = 44;
        $s45 = 45;
        $s46 = 46;
        $s47 = 47;
        $s48 = 48;
        $s49 = 49;
        $s50 = 50;
        $s51 = 51;
        $s52 = 52;
        $s53 = 53;
        $s54 = 54;
        $s55 = 55;
        $s56 = 56;
        $s57 = 57;
        $s58 = 58;
        $s59 = 59;
        $s60 = 60;
        $s61 = 61;
        $s62 = 62;
        $s63 = 63;
        $s64 = 64;
        $s65 = 65;
        $s66 = 66;
        $s67 = 67;
        $s68 = 68;
        $s69 = 69;
        $s70 = 70;
        $s71 = 71;
        $s72 = 72;
        $s73 = 73;
        $s74 = 74;
        $s75 = 75;
        $s76 = 76;
        $s77 = 77;
        $s78 = 78;
        $s79 = 79;
        $s80 = 80;
        $s81 = 81;
        $s82 = 82;
        $s83 = 83;
        $s84 = 84;
        $s85 = 85;
        $s86 = 86;
        $s87 = 87;
        $s88 = 88;
        $s89 = 89;
        $s90 = 90;
        $s91 = 91;
        $s92 = 92;
        $s93 = 93;
        $s94 = 94;
        $s95 = 95;
        $s96 = 96;
        $s97 = 97;
        $s98 = 98;
        $s99 = 99;
        $s100 = 100;

        return User::find($id);
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
    $response = primaryResponseAnnotation(findReturnResolver()->resolvePrimaryResponse(findReturnDescriptor('find')));

    expect($response)->not->toBeNull()
        ->and($response->response)->toBe('200')
        ->and($response->description)->toBe('OK')
        ->and(findReturnRef($response))->toContain('User');
});

it('resolves a returned findOrFail() to a 200 model $ref', function (): void {
    $response = primaryResponseAnnotation(findReturnResolver()->resolvePrimaryResponse(findReturnDescriptor('findOrFail')));

    expect($response)->not->toBeNull()
        ->and(findReturnRef($response))->toContain('User');
});

it('resolves a returned firstOrFail() to a 200 model $ref', function (): void {
    $response = primaryResponseAnnotation(findReturnResolver()->resolvePrimaryResponse(findReturnDescriptor('firstOrFail')));

    expect($response)->not->toBeNull()
        ->and(findReturnRef($response))->toContain('User');
});

it('resolves a returned find() past the first ten statements', function (): void {
    $response = primaryResponseAnnotation(
        findReturnResolver()->resolvePrimaryResponse(findReturnDescriptor('findPastTenStatements')),
    );

    expect($response)->not->toBeNull()
        ->and(findReturnRef($response))->toContain('User');
});

it('refuses a returned find() past the hundred-statement backstop', function (): void {
    expect(findReturnResolver()->resolvePrimaryResponse(findReturnDescriptor('findBeyondBackstop')))->toBeNull();
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
    $response = primaryResponseAnnotation(findReturnResolver()->resolvePrimaryResponse(findReturnDescriptor('article')));

    expect($response)->not->toBeNull()
        ->and(findReturnRef($response))->toContain('Article');
});
