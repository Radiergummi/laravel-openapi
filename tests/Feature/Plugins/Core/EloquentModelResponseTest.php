<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\Core;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

uses()->group('openapi');

class ModelResponseController extends Controller
{
    public function single(): Article
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

it('emits a 200 with a $ref for a single model return type', function (): void {
    Route::get('/articles/{article}', [ModelResponseController::class, 'single']);

    $spec = generateSpec();

    $response = $spec['paths']['/articles/{article}']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['description'])->toBe('OK')
        ->and($response['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/Article')
        ->and($spec['components']['schemas']['Article']['properties'])->toHaveKey('title');
});

it('does not emit a $ref response schema for a non-model return type', function (): void {
    Route::get('/plain-status', fn(): string => 'ok');

    $spec = generateSpec();

    $schema = $spec['paths']['/plain-status']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema['$ref'] ?? null)->toBeNull();
});
