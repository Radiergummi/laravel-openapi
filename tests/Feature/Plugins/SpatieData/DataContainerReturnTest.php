<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\SpatieData;

use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use LogicException;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Tests\Fixtures\ScalarOnlyData;
use Spatie\LaravelData\DataCollection;

use function array_any;
use function collect;
use function str_contains;

uses()->group('openapi', 'plugin:spatie-data');

/** A plain class that is neither a Data nor a Resource: no plugin claims its `collect()`. */
class NotADataOrResource
{
    /**
     * @param array<mixed> $items
     */
    public static function collect(array $items): Collection
    {
        return collect($items);
    }
}

/**
 * Generic-container return types whose item type lives only in the body's Data factory. Actions
 * are parse-only; never invoked, so the bodies need only be syntactically valid.
 */
class DataContainerReturnController extends Controller
{
    public function collectionFromCollect(): Collection
    {
        return ScalarOnlyData::collect(collect([]));
    }

    public function arrayFromCollect(): array
    {
        return ScalarOnlyData::collect([]);
    }

    public function assignedThenReturned(): Collection
    {
        $data = ScalarOnlyData::collect(collect([]));

        return $data;
    }

    public function degradeToService(): Collection
    {
        return collect([]);
    }

    public function nonDataCollect(): Collection
    {
        return NotADataOrResource::collect([]);
    }

    public function conditional(bool $flag): Collection
    {
        if ($flag) {
            return ScalarOnlyData::collect(collect([]));
        }

        return collect([]);
    }

    /**
     * No `@return` generic: the item shape is undeclared.
     */
    public function undeclaredDataCollection(): DataCollection
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

/**
 * @return array<string, mixed>
 */
function containerSchema(string $path): array
{
    $spec = generateSpec();
    $schema = $spec['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull();

    return $schema;
}

it('reads a DataClass::collect() body behind a Collection return type', function (): void {
    Route::get('/data-container/collect', [DataContainerReturnController::class, 'collectionFromCollect']);

    $schema = containerSchema('/data-container/collect');

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['$ref'])->toBe('#/components/schemas/ScalarOnlyData');
});

it('reads a DataClass::collect() body behind a builtin array return type', function (): void {
    Route::get('/data-container/array', [DataContainerReturnController::class, 'arrayFromCollect']);

    $schema = containerSchema('/data-container/array');

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['$ref'])->toBe('#/components/schemas/ScalarOnlyData');
});

it('reads a DataClass::collect() assigned to a variable then returned', function (): void {
    Route::get('/data-container/assigned', [DataContainerReturnController::class, 'assignedThenReturned']);

    $schema = containerSchema('/data-container/assigned');

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['$ref'])->toBe('#/components/schemas/ScalarOnlyData');
});

it('degrades silently to no schema when the body is not a recognisable Data factory', function (): void {
    Route::get('/data-container/degrade', [DataContainerReturnController::class, 'degradeToService']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $response = $spec['paths']['/data-container/degrade']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');

    $warned = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'degradeToService'),
    );

    expect($warned)->toBeFalse();
});

it('returns no schema when the collect() class is not a Data subclass', function (): void {
    Route::get('/data-container/non-data', [DataContainerReturnController::class, 'nonDataCollect']);

    $spec = generateSpec();
    $response = $spec['paths']['/data-container/non-data']['get']['responses']['200'] ?? null;

    expect($response['content'] ?? [])->not->toHaveKey('application/json')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('ScalarOnlyData');
});

it('refuses a conditional / multiple-return body', function (): void {
    Route::get('/data-container/conditional', [DataContainerReturnController::class, 'conditional']);

    $spec = generateSpec();
    $response = $spec['paths']['/data-container/conditional']['get']['responses']['200'] ?? null;

    expect($response['content'] ?? [])->not->toHaveKey('application/json');
});

it('still warns on a typed DataCollection return without a @return generic', function (): void {
    Route::get('/data-container/undeclared', [DataContainerReturnController::class, 'undeclaredDataCollection']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $response = $spec['paths']['/data-container/undeclared']['get']['responses']['200'] ?? null;

    expect($response['content'] ?? [])->not->toHaveKey('application/json');

    $warned = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'item type is undeclared'),
    );

    expect($warned)->toBeTrue();
});
