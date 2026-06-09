<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;

uses()->group('openapi', 'plugin:api-resources');

#[ResourceField('id', type: 'integer')]
#[ResourceField('name', type: 'string')]
class GadgetResource extends JsonResource {}

class GadgetController extends Controller
{
    /** Create a gadget — Resource-typed store reached by POST. */
    public function store(): GadgetResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /** Create a gadget with an explicit 201 #[Response] override. */
    #[Response(status: 201, description: 'Gadget created', ref: GadgetResource::class)]
    public function storeExplicit(): GadgetResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

// #111: a Resource-typed `store` reached by POST must document a single 201 carrying the
// resource schema — not a 200, and not a 201 alongside a duplicate auto-derived 200.
it('#111: Resource-typed store emits a single 201 with the resource body and no 200', function (): void {
    Route::post('/gadgets', [GadgetController::class, 'store']);

    $responses = generateSpec()['paths']['/gadgets']['post']['responses'] ?? [];

    expect($responses)->toHaveKey('201')
        ->and($responses)->not->toHaveKey('200')
        ->and($responses['201']['content']['application/json']['schema']['properties'] ?? null)
        ->toHaveKey('data');
});

// #111: an explicit `#[Response(201, ref: …)]` on a Resource-typed store overrides the
// auto-derived primary rather than coexisting with a spurious 200.
it('#111: explicit #[Response(201)] on a Resource store does not duplicate a 200', function (): void {
    Route::post('/gadgets-explicit', [GadgetController::class, 'storeExplicit']);

    $responses = generateSpec()['paths']['/gadgets-explicit']['post']['responses'] ?? [];

    expect($responses)->toHaveKey('201')
        ->and($responses)->not->toHaveKey('200')
        ->and($responses['201']['description'])->toBe('Gadget created');
});
