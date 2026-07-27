<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\ConditionalArticleResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\ConditionalMergeArticleResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\ConditionalVariableReturnResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\DeclaredAndInferredResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\DynamicToArrayResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\DynamicUnmappedResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\InferredArticleResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\LiteralOnlyResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\MixinValueObjectResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\NestingArticleResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\OpaqueValuesResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\PassthroughArticleResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\SelfReferencingCategoryResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\TypedModelPropertyResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\UnconditionalMergeResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\UnlessArticleResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\UntypedReceiverResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\ValueObjectResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\VariableReturnArticleResource;

use function array_any;
use function array_filter;
use function str_contains;

uses()->group('openapi', 'plugin:api-resources');

class ToArrayInferenceController extends Controller
{
    public function literalOnly(): LiteralOnlyResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function modelFields(): InferredArticleResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function nested(): NestingArticleResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function selfReferencing(): SelfReferencingCategoryResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function conditional(): ConditionalArticleResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function opaque(): OpaqueValuesResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function dynamicWithModel(): DynamicToArrayResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function dynamicUnmapped(): DynamicUnmappedResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function passthrough(): PassthroughArticleResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function declaredAndInferred(): DeclaredAndInferredResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function valueObject(): ValueObjectResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function mixinValueObject(): MixinValueObjectResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function untypedReceiver(): UntypedReceiverResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function typedModelProperty(): TypedModelPropertyResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function variableReturn(): VariableReturnArticleResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function unless(): UnlessArticleResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function conditionalVariableReturn(): ConditionalVariableReturnResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function conditionalMerge(): ConditionalMergeArticleResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function unconditionalMerge(): UnconditionalMergeResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

/**
 * @return array<string, mixed>
 */
function resourceComponent(array $spec, string $key): array
{
    expect($spec['components']['schemas'] ?? [])->toHaveKey($key);

    return $spec['components']['schemas'][$key];
}

// region Literal & model-backed fields

it('infers properties from a pure-literal toArray()', function (): void {
    Route::get('/articles-literal', [ToArrayInferenceController::class, 'literalOnly']);

    $schema = resourceComponent(generateSpec(), 'LiteralOnlyResource');

    expect($schema['properties'])->toHaveKeys(['type', 'version', 'flags', 'meta'])
        ->and($schema['properties']['type']['type'])->toBe('string')
        ->and($schema['properties']['version']['type'])->toBe('integer')
        ->and($schema['properties']['flags']['type'])->toBe('array')
        ->and($schema['properties']['flags']['items']['type'])->toBe('string')
        ->and($schema['properties']['meta']['type'])->toBe('object')
        ->and($schema['properties']['meta']['properties']['nested']['type'])->toBe('boolean')
        ->and($schema['required'])->toBe(['type', 'version', 'flags', 'meta']);
});

it('resolves $this->field references against the wrapped model', function (): void {
    Route::get('/articles-model', [ToArrayInferenceController::class, 'modelFields']);

    $schema = resourceComponent(generateSpec(), 'InferredArticleResource');
    $properties = $schema['properties'];

    expect($properties)->toHaveKeys([
        'id', 'title', 'subtitle', 'published_at', 'status', 'reading_time', 'internal_notes',
        'tags', 'created_at',
    ])
        ->and($properties['title']['type'])->toBe('string')
        ->and($properties['published_at'])->toMatchArray(['type' => 'string', 'format' => 'date-time'])
        ->and($properties['status']['enum'])->toContain('draft')
        ->and($properties['reading_time']['type'])->toBe('integer')
        // $hidden governs the model's own serialization; a key the resource names is output.
        ->and($properties['internal_notes']['type'])->toBe('string')
        // #249: the `array` cast plus a `list<string>` @property generic documents a JSON list.
        ->and($properties['tags']['type'])->toBe('array')
        ->and($properties['tags']['items']['type'])->toBe('string')
        // #250: framework-managed timestamps resolve without explicit model metadata.
        ->and($properties['created_at']['format'])->toBe('date-time');
});

it('keeps every always-present key required', function (): void {
    Route::get('/articles-model', [ToArrayInferenceController::class, 'modelFields']);

    $schema = resourceComponent(generateSpec(), 'InferredArticleResource');

    expect($schema['required'])->toContain('id', 'title', 'subtitle', 'status');
});

// endregion

// region Variable-return toArray()

it('reads a toArray() that assigns the array to a variable and returns it', function (): void {
    Route::get('/articles-variable', [ToArrayInferenceController::class, 'variableReturn']);

    $schema = resourceComponent(generateSpec(), 'VariableReturnArticleResource');
    $properties = $schema['properties'];

    // Same shape the inline-literal form would yield: model-backed scalars plus a nested $ref.
    expect($properties)->toHaveKeys(['id', 'title', 'author'])
        ->and($properties['title']['type'])->toBe('string')
        ->and($properties['author']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and($schema['required'])->toBe(['id', 'title', 'author']);
});

it('falls back to the wrapped model when the returned variable is assigned conditionally', function (): void {
    Route::get('/articles-conditional-variable', [ToArrayInferenceController::class, 'conditionalVariableReturn']);

    $spec = generateSpec();
    $schema = $spec['paths']['/articles-conditional-variable']['get']['responses']['200']['content']['application/json']['schema'];

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/Article')
        ->and($spec['components']['schemas'])->toHaveKey('Article')
        ->and($spec['components']['schemas'])->not->toHaveKey('ConditionalVariableReturnResource');
});

it('reads the base literal when a conditional $data += [...] augments it (Koel SongResource)', function (): void {
    Route::get('/articles-conditional-merge', [ToArrayInferenceController::class, 'conditionalMerge']);

    $schema = resourceComponent(generateSpec(), 'ConditionalMergeArticleResource');
    $properties = $schema['properties'];

    // The base literal resolves; the conditionally-merged `subtitle` key stays unread.
    expect($properties)->toHaveKeys(['id', 'title'])
        ->and($properties)->not->toHaveKey('subtitle')
        ->and($properties['title']['type'])->toBe('string')
        ->and($schema['required'])->toBe(['id', 'title']);
});

it('falls back to the wrapped model when an unconditional write augments the base literal', function (): void {
    Route::get('/articles-unconditional-merge', [ToArrayInferenceController::class, 'unconditionalMerge']);

    $spec = generateSpec();
    $schema = $spec['paths']['/articles-unconditional-merge']['get']['responses']['200']['content']['application/json']['schema'];

    // An always-present element write means the base literal alone would understate the value, so
    // the reader refuses and the wrapped model schema is emitted instead.
    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/Article')
        ->and($spec['components']['schemas'])->toHaveKey('Article')
        ->and($spec['components']['schemas'])->not->toHaveKey('UnconditionalMergeResource');
});

// endregion

// region Nested resources

it('emits $refs for nested resource values and arrays for ::collection()', function (): void {
    Route::get('/articles-nested', [ToArrayInferenceController::class, 'nested']);

    $spec = generateSpec();
    $schema = resourceComponent($spec, 'NestingArticleResource');
    $properties = $schema['properties'];

    expect($properties['author']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and($properties['editor']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and($properties['reviewers']['type'])->toBe('array')
        ->and($properties['reviewers']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');

    // The nested resource's own literal was read transitively.
    $nested = resourceComponent($spec, 'NestedAuthorResource');
    expect($nested['properties'])->toHaveKeys(['id', 'name']);
});

it('terminates on a self-referencing resource via the registry cycle guard', function (): void {
    Route::get('/categories', [ToArrayInferenceController::class, 'selfReferencing']);

    $schema = resourceComponent(generateSpec(), 'SelfReferencingCategoryResource');

    expect($schema['properties']['parent']['$ref'])->toBe('#/components/schemas/SelfReferencingCategoryResource')
        // whenLoaded() inside the constructor argument marks the key conditional.
        ->and($schema['required'] ?? [])->not->toContain('parent');
});

// endregion

// region Conditional idioms

it('marks when()/whenLoaded()/whenCounted() keys optional and resolves their values', function (): void {
    Route::get('/articles-conditional', [ToArrayInferenceController::class, 'conditional']);

    $schema = resourceComponent(generateSpec(), 'ConditionalArticleResource');
    $properties = $schema['properties'];

    expect($properties)->toHaveKeys([
        'id', 'subtitle', 'author', 'editor', 'comments_count', 'merged_always', 'merged_maybe',
    ])
        ->and($properties['author']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        // Bare whenLoaded('editor') resolves through the model's @property-read relation.
        ->and($properties['comments_count']['type'])->toBe('integer')
        ->and($properties['merged_always']['type'])->toBe('string')
        ->and($properties['merged_maybe']['type'])->toBe('integer')
        ->and($schema['required'])->toBe(['id', 'merged_always']);
});

it('marks $this->unless() fields optional, mirroring $this->when()', function (): void {
    Route::get('/articles-unless', [ToArrayInferenceController::class, 'unless']);

    $schema = resourceComponent(generateSpec(), 'UnlessArticleResource');
    $properties = $schema['properties'];

    expect($properties)->toHaveKeys(['id', 'subtitle', 'internal_notes', 'draft_note', 'editor'])
        // unless() resolves its value argument and types it from the model, like when().
        ->and($properties['internal_notes']['type'])->toBe('string')
        // A null value argument has no derivable type, so the optional field stays unconstrained.
        ->and($properties['draft_note'])->toBe([])
        // unless() wrapping a nested resource argument marks the $ref field optional.
        ->and($properties['editor']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        // Both the when()-wrapped and unless()-wrapped keys are absent from required.
        ->and($schema['required'])->toBe(['id']);
});

// endregion

// region Refusals & degradation

it('keeps unresolvable values as unconstrained properties and logs one summarising note', function (): void {
    Route::get('/articles-opaque', [ToArrayInferenceController::class, 'opaque']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $schema = resourceComponent(generateSpec(), 'OpaqueValuesResource');

    expect($schema['properties'])->toHaveKeys(['computed', 'either', 'unknown_field', 'stable'])
        ->and($schema['properties']['computed'])->toBe([])
        ->and($schema['properties']['stable']['type'])->toBe('string');

    $notes = array_filter(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'OpaqueValuesResource')
            && str_contains($record['message'], 'computed'),
    );

    expect($notes)->toHaveCount(1);
});

it('degrades a dynamic toArray() to the wrapped model schema with a note', function (): void {
    Route::get('/articles-dynamic', [ToArrayInferenceController::class, 'dynamicWithModel']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $schema = $spec['paths']['/articles-dynamic']['get']['responses']['200']['content']['application/json']['schema'];

    // The envelope wraps the *model* component; no empty resource component is created.
    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/Article')
        ->and($spec['components']['schemas'])->toHaveKey('Article')
        ->and($spec['components']['schemas'])->not->toHaveKey('DynamicToArrayResource');

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'DynamicToArrayResource')
            && str_contains($record['message'], 'not a single'),
    );

    expect($noted)->toBeTrue();
});

it('degrades a dynamic toArray() without a model to the empty schema with a note', function (): void {
    Route::get('/articles-unmapped', [ToArrayInferenceController::class, 'dynamicUnmapped']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $schema = resourceComponent(generateSpec(), 'DynamicUnmappedResource');

    expect($schema['properties'] ?? [])->toBe([]);

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'DynamicUnmappedResource'),
    );

    expect($noted)->toBeTrue();
});

// endregion

// region Passthrough base case (folded #98)

it('documents a passthrough resource as the wrapped model schema', function (): void {
    Route::get('/articles-passthrough', [ToArrayInferenceController::class, 'passthrough']);

    $spec = generateSpec();
    $schema = $spec['paths']['/articles-passthrough']['get']['responses']['200']['content']['application/json']['schema'];

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/Article')
        ->and($spec['components']['schemas'])->not->toHaveKey('PassthroughArticleResource');
});

// endregion

// region #[ResourceField] precedence

it('lets a #[ResourceField] win per field while inferred fields compose alongside', function (): void {
    Route::get('/articles-declared', [ToArrayInferenceController::class, 'declaredAndInferred']);

    $schema = resourceComponent(generateSpec(), 'DeclaredAndInferredResource');
    $properties = $schema['properties'];

    // 'id' is declared integer (the model would say string) — the attribute wins.
    expect($properties['id']['type'])->toBe('integer')
        ->and($properties['id']['description'])->toBe('Declared identifier.')
        // 'title' is not declared — inferred from the literal + model.
        ->and($properties['title']['type'])->toBe('string')
        ->and($schema['required'])->toContain('id', 'title');
});

// endregion

// region Wrapped non-Model value objects

it('types $this->wrapped->field against a non-Model value object (shape A)', function (): void {
    Route::get('/genres-value-object', [ToArrayInferenceController::class, 'valueObject']);

    $schema = resourceComponent(generateSpec(), 'ValueObjectResource');
    $properties = $schema['properties'];

    expect($properties['id']['type'])->toBe('string')
        ->and($properties['name']['type'])->toBe('string')
        ->and($properties['song_count']['type'])->toBe('integer')
        ->and($properties['length']['type'])->toBe('number')
        // A `'genres'` literal still wins over the wrapped property.
        ->and($properties['type']['type'])->toBe('string');
});

it('keeps a nullable scalar value-object property modelled and nullable', function (): void {
    Route::get('/genres-value-object', [ToArrayInferenceController::class, 'valueObject']);

    $note = resourceComponent(generateSpec(), 'ValueObjectResource')['properties']['note'];

    expect($note['type'])->toBe(['string', 'null']);
});

it('refuses to type a union-typed value-object property (shape A)', function (): void {
    Route::get('/genres-value-object', [ToArrayInferenceController::class, 'valueObject']);

    $mixed = resourceComponent(generateSpec(), 'ValueObjectResource')['properties']['mixed'];

    expect($mixed)->toBe([])
        ->and($mixed)->not->toHaveKey('oneOf');
});

it('types array and `@var`-refined value-object properties (shape A)', function (): void {
    Route::get('/genres-value-object', [ToArrayInferenceController::class, 'valueObject']);

    $properties = resourceComponent(generateSpec(), 'ValueObjectResource')['properties'];

    // A bare native array types as an unconstrained array (previously omitted).
    expect($properties['tags']['type'])->toBe('array')
        ->and($properties['tags'])->not->toHaveKey('properties');

    // A `@var array{…}` refines the native array into an object with typed, required fields.
    expect($properties['meta']['type'])->toBe('object')
        ->and($properties['meta']['properties']['code']['type'])->toBe('string')
        ->and($properties['meta']['properties']['size']['type'])->toBe('integer')
        ->and($properties['meta']['required'])->toEqualCanonicalizing(['code', 'size']);
});

it('refuses to type an absent value-object property (shape A)', function (): void {
    Route::get('/genres-value-object', [ToArrayInferenceController::class, 'valueObject']);

    expect(resourceComponent(generateSpec(), 'ValueObjectResource')['properties']['absent'])->toBe([]);
});

it('refuses when the wrapped receiver has no declared class type (shape A)', function (): void {
    Route::get('/untyped-receiver', [ToArrayInferenceController::class, 'untypedReceiver']);

    expect(resourceComponent(generateSpec(), 'UntypedReceiverResource')['properties']['id'])->toBe([]);
});

it('types $this->field against a non-Model @mixin value object (shape B)', function (): void {
    Route::get('/genres-mixin', [ToArrayInferenceController::class, 'mixinValueObject']);

    $properties = resourceComponent(generateSpec(), 'MixinValueObjectResource')['properties'];

    expect($properties['id']['type'])->toBe('string')
        ->and($properties['song_count']['type'])->toBe('integer')
        // A union-typed source property still refuses under shape B.
        ->and($properties['mixed'])->toBe([]);
});

it('tries the Model path before the value-object path with no Model regression', function (): void {
    Route::get('/articles-model', [ToArrayInferenceController::class, 'modelFields']);

    $properties = resourceComponent(generateSpec(), 'InferredArticleResource')['properties'];

    // The Model-backed fields keep their model-derived types (value-object path never shadows them).
    expect($properties['title']['type'])->toBe('string')
        ->and($properties['reading_time']['type'])->toBe('integer')
        ->and($properties['published_at'])->toMatchArray(['type' => 'string', 'format' => 'date-time']);
});

// endregion

// region Typed Model property (shape A)

it('types $this->wrapped->field from model metadata when the property is a Model', function (): void {
    Route::get('/typed-model-property', [ToArrayInferenceController::class, 'typedModelProperty']);

    $properties = resourceComponent(generateSpec(), 'TypedModelPropertyResource')['properties'];

    expect($properties['published_at'])->toMatchArray(['type' => 'string', 'format' => 'date-time'])
        ->and($properties['summary']['type'])->toBe('string');
});

it('carries the model attribute prose onto a typed Model property read', function (): void {
    Route::get('/typed-model-property', [ToArrayInferenceController::class, 'typedModelProperty']);

    $properties = resourceComponent(generateSpec(), 'TypedModelPropertyResource')['properties'];

    // Prose lives only on `@property` tags, so it proves the model reader produced this schema.
    expect($properties['release_date'])->toMatchArray([
        'type' => 'string',
        'format' => 'date',
        'description' => 'The day the article goes on sale.',
    ]);
});

it('refs the related model and registers its schema for a single relation hop', function (): void {
    Route::get('/typed-model-property', [ToArrayInferenceController::class, 'typedModelProperty']);

    $spec = generateSpec();
    $properties = resourceComponent($spec, 'TypedModelPropertyResource')['properties'];

    expect($properties['relation_single_hop'])->toBe(['$ref' => '#/components/schemas/DatedArticle'])
        ->and($spec['components']['schemas'])->toHaveKey('DatedArticle');
});

it('refuses an attribute the model has no metadata for (typed Model property)', function (): void {
    Route::get('/typed-model-property', [ToArrayInferenceController::class, 'typedModelProperty']);

    $properties = resourceComponent(generateSpec(), 'TypedModelPropertyResource')['properties'];

    expect($properties['unknown_column'])->toBe([])
        // A second hop off the model is not shape (A), so the receiver rule stays closed.
        ->and($properties['relation_hop'])->toBe([]);
});

it('falls back to the statically-typed public property of a Model receiver', function (): void {
    Route::get('/typed-model-property', [ToArrayInferenceController::class, 'typedModelProperty']);

    $properties = resourceComponent(generateSpec(), 'TypedModelPropertyResource')['properties'];

    // The model has no metadata for `slug`, so reaching a type at all proves the public-property
    // read is still consulted for a Model receiver. Only one reader answers here, so this says
    // nothing about their order; the next test covers that.
    expect($properties['public_typed_property']['type'])->toBe('string');
});

it('prefers model metadata over the public property when both type the field', function (): void {
    Route::get('/typed-model-property', [ToArrayInferenceController::class, 'typedModelProperty']);

    $properties = resourceComponent(generateSpec(), 'TypedModelPropertyResource')['properties'];

    // `legacyCode` is cast to integer and declared `public string`, so the two readers disagree and
    // the winner names the one that ran first. Inverting the precedence turns this key into a string.
    expect($properties['both_sources_typed']['type'])->toBe('integer');
});

// endregion
