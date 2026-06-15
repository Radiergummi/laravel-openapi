<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Plugins\Core\Support\SchemaFromFormRequest;
use Radiergummi\OpenApi\Support\Extraction\FakerExampleSynthesiser;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\ExplicitClassSchema;
use Radiergummi\OpenApi\Tests\Fixtures\DeeplyChainedFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FileUploadFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\RouteBoundFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\SimpleFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\UserBoundFormRequest;

uses()->group('openapi');

beforeEach(function (): void {
    $this->registry = new ComponentSchemaRegistry();
    $this->findings = new ArrayFindingsCollector();
    $this->builder  = new SchemaFromFormRequest(
        rulesMapper: new ValidationRulesToSchema(),
        registry: $this->registry,
        logger: new NullLogger(),
        synthesiser: new FakerExampleSynthesiser(enabled: false),
        findings: $this->findings,
        explicitSchema: new ExplicitClassSchema(new NullLogger()),
    );
});

// region Helpers

/**
 * @return array<string, OA\Property>
 */
function formRequestPropertiesByName(OA\Schema $schema): array
{
    if (!is_array($schema->properties)) {
        return [];
    }

    $out = [];

    foreach ($schema->properties as $property) {
        if ($property instanceof OA\Property) {
            $out[$property->property] = $property;
        }
    }

    return $out;
}

// endregion

// region Basic schema building

it('builds properties from FormRequest rules', function (): void {
    $this->builder->build(SimpleFormRequest::class);

    $schema = $this->registry->all()[0] ?? null;
    expect($schema)->toBeInstanceOf(OA\Schema::class);

    $props = formRequestPropertiesByName($schema);

    expect($props)->toHaveKeys(['url', 'name', 'count', 'note']);
});

it('marks required fields in the required list', function (): void {
    $this->builder->build(SimpleFormRequest::class);

    $schema = $this->registry->all()[0];

    expect($schema->required)->toContain('url')
        ->and($schema->required)->toContain('name')
        ->and($schema->required)->not->toContain('count')
        ->and($schema->required)->not->toContain('note');
});

it('sets correct type and constraints on string fields', function (): void {
    $this->builder->build(SimpleFormRequest::class);

    $schema = $this->registry->all()[0];
    $props  = formRequestPropertiesByName($schema);

    expect($props['name']->type)->toBe('string')
        ->and($props['name']->maxLength)->toBe(100);
});

it('sets correct type and constraints on integer fields', function (): void {
    $this->builder->build(SimpleFormRequest::class);

    $schema = $this->registry->all()[0];
    $props  = formRequestPropertiesByName($schema);

    expect($props['count']->type)->toBe('integer')
        ->and($props['count']->minimum)->toBe(1)
        ->and($props['count']->maximum)->toBe(50);
});

it('marks nullable fields using OAS 3.1 type array (Bug 1: no nullable keyword)', function (): void {
    $this->builder->build(SimpleFormRequest::class);

    $schema = $this->registry->all()[0];
    $props  = formRequestPropertiesByName($schema);

    // OAS 3.1 idiom: type: ['string', 'null'] instead of the removed nullable: true keyword.
    expect($props['note']->type)->toBe(['string', 'null']);
});

// endregion

// region #[RequestField] constant overrides

it('merges RequestField attribute from PARAM_* constant onto the property', function (): void {
    $this->builder->build(SimpleFormRequest::class);

    $schema = $this->registry->all()[0];
    $props  = formRequestPropertiesByName($schema);

    // PARAM_URL carries #[RequestField(description: 'The target URL.', example: 'https://example.com', format: 'uri')]
    expect($props['url']->description)->toBe('The target URL.')
        ->and($props['url']->example)->toBe('https://example.com')
        ->and($props['url']->format)->toBe('uri');
});

// endregion

// region File detection

it('returns false from hasFileFields when no file rules present', function (): void {
    expect($this->builder->hasFileFields(SimpleFormRequest::class))->toBeFalse();
});

it('returns true from hasFileFields when file rule is present', function (): void {
    expect($this->builder->hasFileFields(FileUploadFormRequest::class))->toBeTrue();
});

it('builds file field with type=string and format=binary', function (): void {
    $this->builder->build(FileUploadFormRequest::class);

    $schema = $this->registry->all()[0];
    $props  = formRequestPropertiesByName($schema);

    expect($props['attachment']->type)->toBe('string')
        ->and($props['attachment']->format)->toBe('binary');
});

// endregion

// region Error resilience

it('registers a placeholder schema, logs a warning, and emits a finding when rules() throws', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')
        ->once()
        ->withArgs(static fn(string $msg): bool => str_contains($msg, 'SchemaFromFormRequest failed'));

    $findings = new ArrayFindingsCollector();

    $builder = new SchemaFromFormRequest(
        rulesMapper: new ValidationRulesToSchema(),
        registry: $this->registry,
        logger: $logger,
        synthesiser: new FakerExampleSynthesiser(enabled: false),
        findings: $findings,
        explicitSchema: new ExplicitClassSchema(new NullLogger()),
    );

    $brokenClass = new class () extends FormRequest {
        public function rules(): array
        {
            throw new RuntimeException('DB not available');
        }
    };

    $brokenClassName = $brokenClass::class;

    $builder->build($brokenClassName);

    $schemas = $this->registry->all();
    expect($schemas)->toHaveCount(1)
        ->and($schemas[0]->type)->toBe('object')
        ->and($schemas[0]->description)->toContain('Schema introspection failed');

    $emitted = $findings->all();
    expect($emitted)->toHaveCount(1)
        ->and($emitted[0]->ruleId)->toBe('request-body.schema-degraded')
        ->and($emitted[0]->level)->toBe(1)
        ->and($emitted[0]->message)->toContain('DB not available')
        ->and($emitted[0]->message)->toContain($brokenClassName);
});

it('degrades gracefully when rules() raises an Error or TypeError', function (): void {
    // Real apps' rules() routinely raise Error/TypeError at spec time, not just
    // Exception: a typed Rule constructor rejecting the route-binding placeholder
    // (TypeError), or a method call on a null auth user (Error). The userland
    // invocation seam must catch Throwable, not only Exception.
    $brokenClass = new class () extends FormRequest {
        public function rules(): array
        {
            throw new TypeError('Argument #1 ($user) must be of type User, AnyValue given');
        }
    };

    $brokenClassName = $brokenClass::class;

    $this->builder->build($brokenClassName);

    $schemas = $this->registry->all();
    expect($schemas)->toHaveCount(1)
        ->and($schemas[0]->type)->toBe('object')
        ->and($schemas[0]->description)->toContain('Schema introspection failed');

    $emitted = $this->findings->all();
    expect($emitted)->toHaveCount(1)
        ->and($emitted[0]->ruleId)->toBe('request-body.schema-degraded')
        ->and($emitted[0]->message)->toContain('AnyValue given');
});

// endregion

// region Runtime-state stubbing

it('builds a schema for a FormRequest whose rules() reads a route binding', function (): void {
    $schema = $this->builder->build(RouteBoundFormRequest::class);

    expect($schema)->toBeInstanceOf(OA\Schema::class);

    $schemas = $this->registry->all();

    expect($schemas)->toHaveCount(1);

    $properties = formRequestPropertiesByName($schemas[0]);

    expect($properties)->toHaveKeys(['status', 'request_uuid', 'group_uuid', 'error'])
        ->and($properties['request_uuid']->type)->toBe('string')
        ->and($properties['request_uuid']->format)->toBe('uuid');

    expect($this->findings->all())->toBe([]);
});

it('builds a schema for a FormRequest whose rules() reads $this->user()', function (): void {
    $schema = $this->builder->build(UserBoundFormRequest::class);

    expect($schema)->toBeInstanceOf(OA\Schema::class);

    $schemas = $this->registry->all();
    $properties = formRequestPropertiesByName($schemas[0]);

    expect($properties)->toHaveKeys(['email', 'customer_id'])
        ->and($properties['email']->type)->toBe('string')
        ->and($properties['customer_id']->type)->toBe('integer');

    expect($this->findings->all())->toBe([]);
});

it('builds a schema for a FormRequest whose rules() chains deeply through $this->user()', function (): void {
    $schema = $this->builder->build(DeeplyChainedFormRequest::class);

    expect($schema)->toBeInstanceOf(OA\Schema::class);

    $schemas = $this->registry->all();
    $properties = formRequestPropertiesByName($schemas[0]);

    expect($properties)->toHaveKeys(['assigned_to', 'note'])
        ->and($properties['assigned_to']->type)->toBe('integer');

    expect($this->findings->all())->toBe([]);
});

// endregion

// region Idempotency

it('does not double-register when build is called twice', function (): void {
    $this->builder->build(SimpleFormRequest::class);
    $this->builder->build(SimpleFormRequest::class);

    expect($this->registry->all())->toHaveCount(1);
});

it('returns a $ref schema on the second call', function (): void {
    $this->builder->build(SimpleFormRequest::class);
    $ref = $this->builder->build(SimpleFormRequest::class);

    expect($ref->ref)->toContain('#/components/schemas/');
});

// endregion

// region Array items — wildcard rules populate OA\Items on the parent property

it('attaches OA\Items from foo.* rules to the parent array property', function (): void {
    $request = new class () extends FormRequest {
        public function rules(): array
        {
            return [
                'tags'   => ['array'],
                'tags.*' => ['string', 'max:10'],
            ];
        }
    };

    $this->builder->build($request::class);

    $schema = $this->registry->all()[0];
    $props  = formRequestPropertiesByName($schema);

    expect($props)->toHaveKey('tags')
        ->and($props['tags']->type)->toBe('array')
        ->and($props['tags']->items)->toBeInstanceOf(OA\Items::class)
        ->and($props['tags']->items->type)->toBe('string')
        ->and($props['tags']->items->maxLength)->toBe(10);
});

it('attaches a fallback OA\Items to a nullable array property (Bug 1: OAS 3.1 oneOf)', function (): void {
    // Regression: 'nullable','array' with no foo.* rule produced type:array without items,
    // which swagger-php's validator rejects with a fatal ErrorException.
    // OAS 3.1: nullable array uses oneOf: [{type:array,items:…}, {type:null}] so that the inner
    // schema keeps type:'array' as an exact string — swagger-php's OA\Items parent check requires
    // this; a type array (['array','null']) breaks it.
    $request = new class () extends FormRequest {
        public function rules(): array
        {
            return [
                'filters' => ['nullable', 'array'],
            ];
        }
    };

    $this->builder->build($request::class);

    $schema = $this->registry->all()[0];
    $props  = formRequestPropertiesByName($schema);

    expect($props)->toHaveKey('filters');

    $filtersSchema = $props['filters'];

    // The nullable array is wrapped in oneOf: [{type:array,items:…}, {type:null}]
    expect($filtersSchema->oneOf)->toHaveCount(2);

    $arrayBranch = collect($filtersSchema->oneOf)->first(
        fn(OA\Schema $s) => $s->type === 'array',
    );
    $nullBranch = collect($filtersSchema->oneOf)->first(
        fn(OA\Schema $s) => $s->type === 'null',
    );

    expect($arrayBranch)->toBeInstanceOf(OA\Schema::class);
    assert($arrayBranch instanceof OA\Schema);

    expect($arrayBranch->items)->toBeInstanceOf(OA\Items::class)
        ->and($nullBranch)->not->toBeNull();
});

// endregion

// region Wildcard rule keys (dogfooding 2026-05-29 p1-wildcard-rule-key-emits-asterisk-property)

it('translates a bare "*" rule key into additionalProperties, not a literal "*" property', function (): void {
    $this->builder->build(Radiergummi\OpenApi\Tests\Fixtures\WildcardFormRequest::class);

    $schema = $this->registry->all()[0];
    $props  = formRequestPropertiesByName($schema);

    expect($props)->not->toHaveKey('*');

    // `required` must not contain the literal '*' either — that would be an invalid
    // reference to a non-existent property.
    if (is_array($schema->required)) {
        expect($schema->required)->not->toContain('*');
    }

    expect($schema->additionalProperties)->toBeInstanceOf(OA\AdditionalProperties::class);
    assert($schema->additionalProperties instanceof OA\AdditionalProperties);

    expect($schema->additionalProperties->type)->toBe('string')
        ->and($schema->additionalProperties->format)->toBe('uuid');
});

it('synthesises a type:array parent property for "foo.*" rules without a separately declared "foo"', function (): void {
    $this->builder->build(Radiergummi\OpenApi\Tests\Fixtures\BareWildcardArrayFormRequest::class);

    $schema = $this->registry->all()[0];
    $props  = formRequestPropertiesByName($schema);

    expect($props)->toHaveKey('attachments');

    $attachments = $props['attachments'];

    expect($attachments->type)->toBe('array')
        ->and($attachments->items)->toBeInstanceOf(OA\Items::class);
    assert($attachments->items instanceof OA\Items);

    expect($attachments->items->type)->toBe('string')
        ->and($attachments->items->maxLength)->toBe(2048);
});

// endregion
