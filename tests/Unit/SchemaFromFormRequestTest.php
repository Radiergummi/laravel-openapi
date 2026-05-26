<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Core\Extractors\SchemaFromFormRequest;
use Radiergummi\OpenApi\Core\Extractors\ValidationRulesToSchema;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\Examples\FakerExampleSynthesiser;
use Radiergummi\OpenApi\Core\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Tests\Fixtures\FileUploadFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\SimpleFormRequest;

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
