<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Contracts\Registry\QueryParameterResolver;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\RequestBodyExtractor;
use Radiergummi\OpenApi\Support\Extraction\SecurityExtractor;
use Radiergummi\OpenApi\Support\Extraction\UriParametersExtractor;
use Radiergummi\OpenApi\Support\Generator\ExampleFileLoader;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Registry\ResolverFaultBoundary;
use Radiergummi\OpenApi\Support\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Support\Routing\UriParameterResolver;
use Radiergummi\OpenApi\Tests\Fixtures\AuthoringFixtureController;

uses()->group('openapi');

// region Helpers

/**
 * Builds an OperationBuilder from the live collaborators, overriding only the resolver lists and
 * the fault boundary (so the test controls the logger that captures warnings).
 *
 * @param list<PrimaryResponseResolver> $primaryResponseResolvers
 * @param list<QueryParameterResolver>  $queryParameterResolvers
 */
function builderWithResolvers(
    ResolverFaultBoundary $boundary,
    array $primaryResponseResolvers = [],
    array $queryParameterResolvers = [],
): OperationBuilder {
    return new OperationBuilder(
        uriResolver: app(UriParameterResolver::class),
        uriExtractor: app(UriParametersExtractor::class),
        bodyExtractor: app(RequestBodyExtractor::class),
        securityExtractor: app(SecurityExtractor::class),
        fileLoader: app(ExampleFileLoader::class),
        faultBoundary: $boundary,
        docBlockParser: app(DocBlockParser::class),
        queryParameterResolvers: $queryParameterResolvers,
        primaryResponseResolvers: $primaryResponseResolvers,
    );
}

function faultIsolationDescriptor(): ActionDescriptor
{
    Route::get('/fault-isolation', [AuthoringFixtureController::class, 'publicAction']);

    $descriptors = array_values(array_filter(
        iterator_to_array(app(RouteIntrospector::class)->discover(), false),
        static fn(ActionDescriptor $d): bool => $d->method?->getName() === 'publicAction'
            && $d->controller?->getName() === AuthoringFixtureController::class,
    ));

    expect($descriptors)->toHaveCount(1);

    return $descriptors[0];
}

// endregion

// region Exception isolation

it('skips a primary-response resolver that throws and still emits the fallback 200', function (): void {
    $logger   = recordingLogger();
    $throwing = new class () implements PrimaryResponseResolver {
        public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
        {
            throw new RuntimeException('resolver blew up');
        }
    };

    $builder = builderWithResolvers(
        new ResolverFaultBoundary($logger),
        primaryResponseResolvers: [$throwing],
    );

    $op = $builder->build(faultIsolationDescriptor(), []);

    expect($op->responses[0]->response)->toBe('200')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('fault-isolation')
        ->and($logger->records[0]['message'])->toContain('resolver blew up');
});

it('keeps the surviving resolvers output when one query-parameter resolver throws', function (): void {
    $logger = recordingLogger();

    $throwing = new class () implements QueryParameterResolver {
        public function resolveQueryParameters(ActionDescriptor $descriptor): array
        {
            throw new RuntimeException('query resolver failed');
        }
    };

    $healthy = new class () implements QueryParameterResolver {
        public function resolveQueryParameters(ActionDescriptor $descriptor): array
        {
            return [new OA\Parameter(['name' => 'survivor', 'in' => 'query'])];
        }
    };

    $builder = builderWithResolvers(
        new ResolverFaultBoundary($logger),
        queryParameterResolvers: [$throwing, $healthy],
    );

    $op = $builder->build(faultIsolationDescriptor(), []);

    $names = array_map(static fn(OA\Parameter $p): string => (string) $p->name, $op->parameters);

    expect($names)->toContain('survivor')
        ->and($logger->records)->toHaveCount(1);
});

// endregion

// region Programming errors propagate

it('lets a TypeError from a resolver abort the build instead of swallowing it', function (): void {
    $logger   = recordingLogger();
    $throwing = new class () implements PrimaryResponseResolver {
        public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
        {
            throw new TypeError('programming bug');
        }
    };

    $builder    = builderWithResolvers(new ResolverFaultBoundary($logger), primaryResponseResolvers: [$throwing]);
    $descriptor = faultIsolationDescriptor();

    expect(fn(): mixed => $builder->build($descriptor, []))->toThrow(TypeError::class);
});

// endregion
