<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Radiergummi\OpenApi\Tests\Fixtures\SchemaNameData;

uses()->group('openapi', 'plugin:spatie-data');

class SchemaNameController extends Controller
{
    public function show(): SchemaNameData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

it('names the component schema after #[SchemaName], not the class basename', function (): void {
    Route::get('/schema-name/show', [SchemaNameController::class, 'show']);

    $spec = generateSpec();

    $ref = $spec['paths']['/schema-name/show']['get']['responses']['200']['content']['application/json']['schema']['$ref'] ?? null;

    expect($ref)->toBe('#/components/schemas/CustomerProfile')
        ->and($spec['components']['schemas'])->toHaveKey('CustomerProfile')
        ->and($spec['components']['schemas'])->not->toHaveKey('SchemaNameData');
});
