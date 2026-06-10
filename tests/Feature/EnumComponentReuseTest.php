<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Radiergummi\OpenApi\Tests\Fixtures\StatusFixtureEnum;

uses()->group('openapi');

/** A FormRequest constraining a field to a backed enum via `Rule::enum()`. */
class EnumReuseFormRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(StatusFixtureEnum::class)],
        ];
    }
}

class EnumReuseController extends Controller
{
    /** Create a thing — request body constrains `status` to the enum via a validation rule. */
    public function store(EnumReuseFormRequest $request): void {}

    /** Show a thing — the path parameter is typed as the same backed enum. */
    public function show(StatusFixtureEnum $status): void {}
}

it('OAPI-035: a backed enum used in two contexts emits one component referenced twice', function (): void {
    Route::post('/enum/things', [EnumReuseController::class, 'store']);
    Route::get('/enum/things/{status}', [EnumReuseController::class, 'show']);

    $doc = generateSpec();
    $schemas = $doc['components']['schemas'];

    // Exactly one component carries the enum's case list — no per-context duplication.
    $enumKeys = array_keys(array_filter(
        $schemas,
        static fn(array $schema): bool => ($schema['enum'] ?? null) === ['active', 'archived', 'draft'],
    ));

    expect($enumKeys)->toHaveCount(1);

    $enumKey = $enumKeys[0];
    $pointer = "#/components/schemas/{$enumKey}";

    // The component carries the backing type and the full case list.
    expect($schemas[$enumKey]['type'])->toBe('string')
        ->and($schemas[$enumKey]['enum'])->toBe(['active', 'archived', 'draft']);

    // Context 1 — validation rule (request body property) references the shared component.
    expect($schemas['EnumReuseFormRequest']['properties']['status']['$ref'] ?? null)->toBe($pointer);

    // Context 2 — type-derived path parameter references the same shared component.
    $parameters = $doc['paths']['/enum/things/{status}']['get']['parameters'];
    $statusParam = collect($parameters)->firstWhere('name', 'status');

    expect($statusParam['schema']['$ref'] ?? null)->toBe($pointer);
});
