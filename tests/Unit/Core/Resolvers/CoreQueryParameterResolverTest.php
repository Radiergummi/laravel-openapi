<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\CoreQueryParameterResolver;
use Radiergummi\OpenApi\Plugins\Core\Support\InlineValidatorRulesReader;
use Radiergummi\OpenApi\Plugins\Core\Support\RequestQueryAccessorReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\QueryAccessorFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi');

// region Helpers

function makeCoreQueryParameterResolver(?LoggerInterface $logger = null): CoreQueryParameterResolver
{
    $scanner = new MethodBodyScanner();

    return new CoreQueryParameterResolver(
        accessorReader: new RequestQueryAccessorReader($scanner),
        validationReader: new InlineValidatorRulesReader($scanner),
        rulesMapper: new ValidationRulesToSchema(),
        logger: $logger ?? new NullLogger(),
    );
}

function makeQueryAccessorDescriptor(string $method, string $verb = 'GET'): ActionDescriptor
{
    return ActionDescriptorFactory::forControllerMethod(
        QueryAccessorFixtureController::class,
        $method,
        '/x',
        [$verb],
    );
}

/**
 * @param list<OA\Parameter> $parameters
 */
function queryParameterNamed(array $parameters, string $name): ?OA\Parameter
{
    foreach ($parameters as $parameter) {
        if ($parameter->name === $name) {
            return $parameter;
        }
    }

    return null;
}

/**
 * @param list<OA\Parameter> $parameters
 *
 * @return list<string>
 */
function queryParameterNames(array $parameters): array
{
    return array_map(static fn(OA\Parameter $parameter): string => $parameter->name, $parameters);
}

// endregion

// region Accessor shapes

it('maps each whitelisted accessor to a query parameter with its inferred type', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('index'));

    expect(queryParameterNames($parameters))->toBe(['sort', 'q', 'name', 'page', 'active'])
        ->and(queryParameterNamed($parameters, 'sort')?->schema->type)->toBe('string')
        ->and(queryParameterNamed($parameters, 'q')?->schema->type)->toBe('string')
        ->and(queryParameterNamed($parameters, 'name')?->schema->type)->toBe('string')
        ->and(queryParameterNamed($parameters, 'page')?->schema->type)->toBe('integer')
        ->and(queryParameterNamed($parameters, 'active')?->schema->type)->toBe('boolean')
        ->and(queryParameterNamed($parameters, 'sort')?->required)->toBeFalse()
        ->and(queryParameterNamed($parameters, 'sort')?->in)->toBe('query');
});

it('keeps a literal default only when it matches the inferred type', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('withDefaults'));

    expect(queryParameterNamed($parameters, 'sort')?->schema->default)->toBe('asc')
        ->and(queryParameterNamed($parameters, 'per_page')?->schema->default)->toBe(25)
        ->and(queryParameterNamed($parameters, 'archived')?->schema->default)->toBe(false)
        // query('page', 1): integer default on a string parameter — omitted.
        ->and(queryParameterNamed($parameters, 'page')?->schema->default)->toBe(Generator::UNDEFINED);
});

it('resolves named arguments (key:, default:) regardless of order', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('namedArguments'));

    expect(queryParameterNamed($parameters, 'filter')?->schema->type)->toBe('string')
        ->and(queryParameterNamed($parameters, 'limit')?->schema->type)->toBe('integer')
        ->and(queryParameterNamed($parameters, 'limit')?->schema->default)->toBe(10);
});

it('accepts the zero-argument request() helper as receiver', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('viaRequestHelper'));

    expect(queryParameterNames($parameters))->toBe(['locale']);
});

it('reports a dotted accessor key in wire notation', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('dottedKey'));

    expect(queryParameterNames($parameters))->toBe(['filter[name]']);
});

it('matches a read inside a conditional context — a read is a read', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('conditionalRead'));

    expect(queryParameterNames($parameters))->toBe(['compact'])
        ->and(queryParameterNamed($parameters, 'compact')?->schema->type)->toBe('boolean');
});

it('dedupes repeated reads of one name, preferring the typed accessor', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('duplicateReads'));

    expect(queryParameterNames($parameters))->toBe(['page', 'q'])
        ->and(queryParameterNamed($parameters, 'page')?->schema->type)->toBe('integer')
        ->and(queryParameterNamed($parameters, 'q')?->schema->type)->toBe('string');
});

// endregion

// region Verb discipline

it('matches only query() on body-carrying verbs', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('index', 'POST'));

    // input()/string()/integer()/boolean() read the merged body+query input — on POST they
    // overwhelmingly mean body fields, so only the query() read survives.
    expect(queryParameterNames($parameters))->toBe(['sort']);
});

it('matches every accessor on HEAD like on GET', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('index', 'HEAD'));

    expect(queryParameterNames($parameters))->toBe(['sort', 'q', 'name', 'page', 'active']);
});

// endregion

// region Degrade paths

it('skips a non-literal parameter name with a generation-log note, keeping literal reads', function (): void {
    $logger = recordingLogger();
    $parameters = makeCoreQueryParameterResolver($logger)
        ->resolveQueryParameters(makeQueryAccessorDescriptor('nonLiteralName'));

    expect(queryParameterNames($parameters))->toBe(['sort'])
        ->and(array_any(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'non-literal parameter name'),
        ))->toBeTrue();
});

it('ignores a zero-argument query() bag read', function (): void {
    $logger = recordingLogger();
    $parameters = makeCoreQueryParameterResolver($logger)
        ->resolveQueryParameters(makeQueryAccessorDescriptor('wholeBag'));

    expect($parameters)->toBe([])
        ->and($logger->records)->toBe([]);
});

it('does not match a read past the first 10 statements', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('lateRead'));

    expect($parameters)->toBe([]);
});

it('never matches an impostor receiver with the same accessor surface', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('impostorReceiver'));

    expect($parameters)->toBe([]);
});

it('never matches a request-named variable that is not the Request parameter', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('requestlessVariable'));

    expect($parameters)->toBe([]);
});

// endregion

// region #[QueryParam] precedence

it('lets an explicit #[QueryParam] win entirely for the same name and composes other names', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('attributeOverride'));

    $sort = queryParameterNamed($parameters, 'sort');

    expect(queryParameterNames($parameters))->toBe(['sort', 'q'])
        ->and($sort?->schema->description)->toBe('Sort order.')
        ->and($sort?->schema->enum)->toBe(['asc', 'desc'])
        ->and(queryParameterNamed($parameters, 'q')?->schema->type)->toBe('string');
});

// endregion

// region GET inline-validate hand-off

it('routes inline validate() keys on a GET route into query parameters', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('search'));

    $search = queryParameterNamed($parameters, 'q');
    $page = queryParameterNamed($parameters, 'page');

    expect(queryParameterNames($parameters))->toBe(['q', 'page'])
        ->and($search?->required)->toBeTrue()
        ->and($search?->schema->type)->toBe('string')
        ->and($search?->schema->maxLength)->toBe(100)
        ->and($search?->schema->description)->toBe('Free-text search query.')
        ->and($page?->required)->toBeFalse()
        ->and($page?->schema->type)->toBe('integer')
        ->and($page?->schema->minimum)->toBe(1);
});

it('does not route inline validate() keys into query parameters on a POST route', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('search', 'POST'));

    expect($parameters)->toBe([]);
});

it('maps nested and array rule keys to wire notation and drops object arrays with a note', function (): void {
    $logger = recordingLogger();
    $parameters = makeCoreQueryParameterResolver($logger)
        ->resolveQueryParameters(makeQueryAccessorDescriptor('nestedSearch'));

    $filterName = queryParameterNamed($parameters, 'filter[name]');
    $identifiers = queryParameterNamed($parameters, 'ids[]');

    expect(queryParameterNames($parameters))->toBe(['filter[name]', 'ids[]'])
        ->and($filterName?->required)->toBeTrue()
        ->and($filterName?->schema->type)->toBe('string')
        ->and($identifiers?->required)->toBeFalse()
        ->and($identifiers?->schema->type)->toBe('array')
        ->and($identifiers?->schema->items->type)->toBe('integer')
        ->and(array_any(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'rows')
                && str_contains($record['message'], 'cannot be expressed as query parameters'),
        ))->toBeTrue();
});

it('degrades dynamic rules on a GET route with a generation-log note', function (): void {
    $logger = recordingLogger();
    $parameters = makeCoreQueryParameterResolver($logger)
        ->resolveQueryParameters(makeQueryAccessorDescriptor('dynamicValidated'));

    expect($parameters)->toBe([])
        ->and(array_any(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'no query parameters inferred'),
        ))->toBeTrue();
});

it('lets validate() rules win over an accessor read of the same name', function (): void {
    $parameters = makeCoreQueryParameterResolver()
        ->resolveQueryParameters(makeQueryAccessorDescriptor('validatedAndRead'));

    $search = queryParameterNamed($parameters, 'q');

    expect(queryParameterNames($parameters))->toBe(['q'])
        ->and($search?->required)->toBeTrue()
        ->and($search?->schema->maxLength)->toBe(100);
});

// endregion
