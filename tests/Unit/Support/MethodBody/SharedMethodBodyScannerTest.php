<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Routing\ResourceTargetLocator;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\CoreQueryParameterResolver;
use Radiergummi\OpenApi\Plugins\Core\Support\SchemaFromFormRequest;
use Radiergummi\OpenApi\Plugins\SpatieData\Resolvers\DataResponseResolver;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;

uses()->group('openapi');

// Each production resolver chain autowires the one #[Scoped] MethodBodyScanner. The three chains
// that previously hand-rolled a fresh scanner (via `new` ctor defaults / factories) must instead
// reach the shared instance so a controller file is parsed once per run, not once per chain.
dataset('scanner_chains', [
    'FormRequest query-parameter chain' => [CoreQueryParameterResolver::class],
    'FormRequest request-body chain' => [SchemaFromFormRequest::class],
    'ApiResources return-expression chain' => [ResourceTargetLocator::class],
    'SpatieData return-expression chain' => [DataResponseResolver::class],
]);

it('reaches the same scoped MethodBodyScanner down every resolver chain', function (string $class): void {
    $sharedScanner = app(MethodBodyScanner::class);

    $reached = reachableMethodBodyScanner(app($class));

    expect($reached)
        ->not->toBeNull()
        ->and($reached)->toBe($sharedScanner);
})->with('scanner_chains');

it('reaches the same scanner across chains within one scope', function (): void {
    $viaQueryParameters = reachableMethodBodyScanner(app(CoreQueryParameterResolver::class));
    $viaRequestBody = reachableMethodBodyScanner(app(SchemaFromFormRequest::class));
    $viaResource = reachableMethodBodyScanner(app(ResourceTargetLocator::class));
    $viaData = reachableMethodBodyScanner(app(DataResponseResolver::class));

    expect($viaQueryParameters)
        ->toBe($viaRequestBody)
        ->and($viaResource)->toBe($viaQueryParameters)
        ->and($viaData)->toBe($viaQueryParameters);
});

it('yields a fresh scanner in a new scope', function (): void {
    $firstScopeScanner = reachableMethodBodyScanner(app(CoreQueryParameterResolver::class));

    // Octane resets scoped bindings between requests; a new scope must get its own scanner (with an
    // empty cache), never the previous scope's stale one.
    app()->forgetScopedInstances();

    $secondScopeScanner = reachableMethodBodyScanner(app(CoreQueryParameterResolver::class));

    expect($secondScopeScanner)
        ->not->toBeNull()
        ->and($secondScopeScanner)->not->toBe($firstScopeScanner);
});

/**
 * Depth-first walk of an object's private/protected properties, returning the first
 * {@see MethodBodyScanner} instance reachable through the graph, or null if none is present.
 */
function reachableMethodBodyScanner(object $root): ?MethodBodyScanner
{
    $seen = [];
    $queue = [$root];

    while ($queue !== []) {
        $current = array_shift($queue);

        if ($current instanceof MethodBodyScanner) {
            return $current;
        }

        $hash = spl_object_id($current);

        if (isset($seen[$hash])) {
            continue;
        }

        $seen[$hash] = true;

        foreach (new ReflectionObject($current)->getProperties() as $property) {
            if (!$property->isInitialized($current)) {
                continue;
            }

            $value = $property->getValue($current);

            if (is_object($value)) {
                $queue[] = $value;
            }
        }
    }

    return null;
}
