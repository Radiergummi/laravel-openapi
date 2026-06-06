<?php

declare(strict_types=1);

use OpenApi\Generator;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Attributes\PathParam;
use Radiergummi\OpenApi\Support\Extraction\UriParametersExtractor;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\Routing\ModelPrimaryKey;
use Radiergummi\OpenApi\Support\Routing\UriParameterDescriptor;
use Radiergummi\OpenApi\Support\Routing\WhereKind;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\ArticleStatus;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\PriorityLevel;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeIdentifier;

uses()->group('routing', 'openapi');

function stringDescriptor(string $name, bool $optional = false): UriParameterDescriptor
{
    return new UriParameterDescriptor(
        name: $name,
        type: Type::builtin(TypeIdentifier::STRING),
        optional: $optional,
        whereConstraint: null,
        whereKind: null,
        modelClass: null,
        routeKeyName: null,
        enumCases: null,
        bindingField: null,
        modelPrimaryKey: null,
    );
}

/**
 * Builds a model-bound descriptor for the given primary-key metadata, mirroring what
 * `UriParameterResolver` produces for an Eloquent route-model binding.
 */
function boundDescriptor(
    ModelPrimaryKey $modelPrimaryKey,
    string $routeKeyName = 'id',
    ?string $bindingField = null,
    ?WhereKind $whereKind = null,
): UriParameterDescriptor {
    return new UriParameterDescriptor(
        name: 'article',
        type: Type::builtin(TypeIdentifier::STRING),
        optional: false,
        whereConstraint: null,
        whereKind: $whereKind,
        modelClass: Article::class,
        routeKeyName: $routeKeyName,
        enumCases: null,
        bindingField: $bindingField,
        modelPrimaryKey: $modelPrimaryKey,
    );
}

beforeEach(function (): void {
    $this->extractor = new UriParametersExtractor(new JsonSchemaFromType(new NullLogger()));
});

it('enriches a path parameter with description and example from #[PathParam]', function (): void {
    $param = reflectFunctionParameter(
        static function (
            #[PathParam(description: 'The company to retrieve.', example: '01HFP-EXAMPLE')]
            string $company,
        ): void {},
        'company',
    );

    [$parameter] = $this->extractor->extract([[stringDescriptor('company'), $param]]);

    expect($parameter->description)->toBe('The company to retrieve.')
        ->and($parameter->schema->example)->toBe('01HFP-EXAMPLE');
});

it('omits the description when no #[PathParam] attribute is present', function (): void {
    $param = reflectFunctionParameter(static function (string $company): void {}, 'company');

    [$parameter] = $this->extractor->extract([[stringDescriptor('company'), $param]]);

    expect($parameter->description)->toBe(Generator::UNDEFINED)
        ->and($parameter->name)->toBe('company')
        ->and($parameter->required)->toBeTrue();
});

it('tolerates a missing reflection parameter', function (): void {
    [$parameter] = $this->extractor->extract([[stringDescriptor('company'), null]]);

    expect($parameter->name)->toBe('company')
        ->and($parameter->description)->toBe(Generator::UNDEFINED);
});

it('always emits required:true for optional path parameters per OAS 3.x §4.8.12.1', function (): void {
    [$parameter] = $this->extractor->extract([[stringDescriptor('path', optional: true), null]]);

    expect($parameter->required)->toBeTrue()
        ->and($parameter->description)
        ->toBe('Optional in URL — the segment may be omitted when calling this route.');
});

it('describes a model binding by its custom field and class basename, not the FQCN', function (): void {
    $descriptor = new UriParameterDescriptor(
        name: 'post',
        type: Type::builtin(TypeIdentifier::STRING),
        optional: false,
        whereConstraint: null,
        whereKind: null,
        modelClass: Article::class,
        routeKeyName: 'id',
        enumCases: null,
        bindingField: 'slug',
        modelPrimaryKey: null,
    );

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->description)->toBe('Bound by slug of Article.');
});

it('falls back to the route key name (still by basename) without a custom field', function (): void {
    $descriptor = new UriParameterDescriptor(
        name: 'post',
        type: Type::builtin(TypeIdentifier::STRING),
        optional: false,
        whereConstraint: null,
        whereKind: null,
        modelClass: Article::class,
        routeKeyName: 'uuid',
        enumCases: null,
        bindingField: null,
        modelPrimaryKey: null,
    );

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->description)->toBe('Bound by uuid of Article.');
});

it('types an int-keyed model binding as integer', function (): void {
    $descriptor = boundDescriptor(new ModelPrimaryKey('id', 'integer', null));

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->schema->type)->toBe('integer')
        ->and($parameter->schema->format)->toBe(Generator::UNDEFINED);
});

it('types a uuid-keyed model binding as string with format uuid', function (): void {
    $descriptor = boundDescriptor(new ModelPrimaryKey('id', 'string', 'uuid'));

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->schema->type)->toBe('string')
        ->and($parameter->schema->format)->toBe('uuid');
});

it('types a string-keyed model binding as a bare string', function (): void {
    $descriptor = boundDescriptor(new ModelPrimaryKey('id', 'string', null));

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->schema->type)->toBe('string')
        ->and($parameter->schema->format)->toBe(Generator::UNDEFINED);
});

it('does not apply the primary-key type to a custom binding field', function (): void {
    // {article:slug} binds by `slug`, not the int primary key `id` — so the int type must
    // not leak onto the slug parameter.
    $descriptor = boundDescriptor(
        new ModelPrimaryKey('id', 'integer', null),
        bindingField: 'slug',
    );

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->schema->type)->toBe('string')
        ->and($parameter->schema->format)->toBe(Generator::UNDEFINED);
});

it('does not apply the primary-key type when the route key is not the primary key', function (): void {
    // A model overriding getRouteKeyName() to `slug` while keeping an int `id` PK: the PK type
    // describes `id`, not `slug`, so it must not be applied.
    $descriptor = boundDescriptor(
        new ModelPrimaryKey('id', 'integer', null),
        routeKeyName: 'slug',
    );

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->schema->type)->toBe('string')
        ->and($parameter->schema->format)->toBe(Generator::UNDEFINED);
});

it('lets an explicit where constraint win over model primary-key metadata', function (): void {
    // whereNumber() (WhereKind::Number) is the author's stated intent and must win even when the
    // model's key metadata says string+uuid.
    $descriptor = boundDescriptor(
        new ModelPrimaryKey('id', 'string', 'uuid'),
        whereKind: WhereKind::Number,
    );

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->schema->type)->toBe('integer')
        ->and($parameter->schema->format)->toBe(Generator::UNDEFINED);
});

it('appends the optional-in-URL note to an existing #[PathParam] description', function (): void {
    $param = reflectFunctionParameter(
        static function (
            #[PathParam(description: 'The trailing segment.')]
            ?string $path,
        ): void {},
        'path',
    );

    [$parameter] = $this->extractor->extract([[stringDescriptor('path', optional: true), $param]]);

    expect($parameter->required)->toBeTrue()
        ->and($parameter->description)->toBe(
            'The trailing segment. Optional in URL — the segment may be omitted when calling this route.',
        );
});

/**
 * Builds an enum-bound descriptor, mirroring what `UriParameterResolver` produces for a path
 * segment type-hinted as a `BackedEnum` (no route-level `where`/`whereIn` constraint).
 *
 * @param class-string<BackedEnum> $enum
 * @param list<string>             $cases
 */
function enumDescriptor(string $enum, array $cases): UriParameterDescriptor
{
    return new UriParameterDescriptor(
        name: 'status',
        type: Type::enum($enum),
        optional: false,
        whereConstraint: null,
        whereKind: null,
        modelClass: null,
        routeKeyName: null,
        enumCases: $cases,
        bindingField: null,
        modelPrimaryKey: null,
    );
}

it('surfaces a string-backed enum binding as a string enum, without a where constraint', function (): void {
    $descriptor = enumDescriptor(ArticleStatus::class, ['draft', 'published']);

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->schema->type)->toBe('string')
        ->and($parameter->schema->enum)->toBe(['draft', 'published']);
});

it('surfaces an int-backed enum binding as an integer enum, without a where constraint', function (): void {
    $descriptor = enumDescriptor(PriorityLevel::class, ['1', '2', '3']);

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->schema->type)->toBe('integer')
        ->and($parameter->schema->enum)->toBe([1, 2, 3]);
});
