<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Core\Attributes\ResponseResource;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi', 'plugin:api-resources');

#[ResourceField('id', type: 'integer')]
#[ResourceField('name', type: 'string')]
class WidgetResource extends JsonResource {}

class WidgetCollection extends ResourceCollection {}

class WidgetResourceController extends Controller
{
    /** Show a widget. */
    public function show(): WidgetResource
    {
        /** @phpstan-ignore-next-line */
        return new WidgetResource(null);
    }

    /** List widgets — item class declared by attribute. */
    #[ResponseResource(WidgetResource::class, collection: true)]
    public function index(): WidgetCollection
    {
        /** @phpstan-ignore-next-line */
        return new WidgetCollection([]);
    }

    /** List widgets — item class undeclared. */
    public function ambiguous(): WidgetCollection
    {
        /** @phpstan-ignore-next-line */
        return new WidgetCollection([]);
    }
}

it('documents a single resource wrapped in a data object', function (): void {
    Route::get('/widgets/{widget}', [WidgetResourceController::class, 'show']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $schema = $spec['paths']['/widgets/{widget}']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKey('data');
});

it('documents a resource collection with data/links/meta', function (): void {
    Route::get('/widgets', [WidgetResourceController::class, 'index']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $schema = $spec['paths']['/widgets']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['type'])->toBe('array');
});

it('registers the resource as a reusable component schema', function (): void {
    Route::get('/widgets/{widget}', [WidgetResourceController::class, 'show']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    expect($spec['components']['schemas'] ?? [])->toHaveKey('WidgetResource');
});

it('falls back to a bare 200 for an ambiguous collection endpoint', function (): void {
    Route::get('/widgets-ambiguous', [WidgetResourceController::class, 'ambiguous']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $response = $spec['paths']['/widgets-ambiguous']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');
});
