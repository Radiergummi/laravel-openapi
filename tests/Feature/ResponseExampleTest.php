<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\ResponseExample;

uses()->group('openapi');

class ResponseExampleFixtureController extends Controller
{
    // A well-formed inline #[ResponseExample] on the 200 response. The attribute scaffolds a JSON
    // media type when the response has none, so a bare JsonResponse return is enough to exercise it.
    #[ResponseExample(status: 200, name: 'success', value: ['id' => 'abc-123', 'name' => 'Widget'])]
    public function show(): JsonResponse
    {
        return new JsonResponse();
    }
}

it('surfaces a #[ResponseExample] under the matching response content', function (): void {
    Route::get('/oa-86/response-example', [ResponseExampleFixtureController::class, 'show']);

    $example = generateSpec()['paths']['/oa-86/response-example']['get']
        ['responses']['200']['content']['application/json']['examples']['success'];

    expect($example['value'])->toBe(['id' => 'abc-123', 'name' => 'Widget']);
});
