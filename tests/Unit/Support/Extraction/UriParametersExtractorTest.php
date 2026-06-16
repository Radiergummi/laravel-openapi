<?php

declare(strict_types=1);

use OpenApi\Generator;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Attributes\PathParam;
use Radiergummi\OpenApi\Support\Extraction\UriParametersExtractor;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\Routing\RouteModelBinding;
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
        enumCases: null,
        modelBinding: null,
    );
}

/**
 * Builds a model-bound descriptor for the given binding, mirroring what `UriParameterResolver`
 * produces for an Eloquent route-model binding.
 */
function boundDescriptor(
    RouteModelBinding $modelBinding,
    ?WhereKind $whereKind = null,
): UriParameterDescriptor {
    return new UriParameterDescriptor(
        name: 'article',
        type: Type::builtin(TypeIdentifier::STRING),
        optional: false,
        whereConstraint: null,
        whereKind: $whereKind,
        enumCases: null,
        modelBinding: $modelBinding,
    );
}

beforeEach(function (): void {
    $this->registry = new ComponentSchemaRegistry();
    $this->extractor = new UriParametersExtractor(new JsonSchemaFromType(new NullLogger(), $this->registry));
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

    expect($parameter->description)
        ->toBe('The company to retrieve.')
        ->and($parameter->schema->example)->toBe('01HFP-EXAMPLE');
});

it('omits the description when no #[PathParam] attribute is present', function (): void {
    $param = reflectFunctionParameter(static function (string $company): void {}, 'company');

    [$parameter] = $this->extractor->extract([[stringDescriptor('company'), $param]]);

    expect($parameter->description)
        ->toBe(Generator::UNDEFINED)
        ->and($parameter->name)->toBe('company')
        ->and($parameter->required)->toBeTrue();
});

it('tolerates a missing reflection parameter', function (): void {
    [$parameter] = $this->extractor->extract([[stringDescriptor('company'), null]]);

    expect($parameter->name)
        ->toBe('company')
        ->and($parameter->description)->toBe(Generator::UNDEFINED);
});

it('always emits required:true for optional path parameters per OAS 3.x §4.8.12.1', function (): void {
    [$parameter] = $this->extractor->extract([[stringDescriptor('path', optional: true), null]]);

    expect($parameter->required)
        ->toBeTrue()
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
        enumCases: null,
        modelBinding: new RouteModelBinding(Article::class, 'slug', null, null),
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
        enumCases: null,
        modelBinding: new RouteModelBinding(Article::class, 'uuid', null, null),
    );

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->description)->toBe('Bound by uuid of Article.');
});

it('types an int-keyed model binding as integer', function (): void {
    $descriptor = boundDescriptor(new RouteModelBinding(Article::class, 'id', 'integer', null));

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->schema->type)
        ->toBe('integer')
        ->and($parameter->schema->format)->toBe(Generator::UNDEFINED);
});

it('types a uuid-keyed model binding as string with format uuid', function (): void {
    $descriptor = boundDescriptor(new RouteModelBinding(Article::class, 'id', 'string', 'uuid'));

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->schema->type)
        ->toBe('string')
        ->and($parameter->schema->format)->toBe('uuid');
});

it('types a string-keyed model binding as a bare string', function (): void {
    $descriptor = boundDescriptor(new RouteModelBinding(Article::class, 'id', 'string', null));

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->schema->type)
        ->toBe('string')
        ->and($parameter->schema->format)->toBe(Generator::UNDEFINED);
});

it('leaves the schema untouched when the binding key carries no type', function (): void {
    // The resolver only types a binding when it resolves by the model's typed primary key; a
    // custom `{article:slug}` field (or a non-Eloquent routable) yields a null type, which must
    // leave the parameter's PHP-derived string type to stand. The PK-vs-key decision itself is
    // covered in UriParameterResolverTest.
    $descriptor = boundDescriptor(new RouteModelBinding(Article::class, 'slug', null, null));

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->schema->type)
        ->toBe('string')
        ->and($parameter->schema->format)->toBe(Generator::UNDEFINED);
});

it('lets an explicit where constraint win over model binding metadata', function (): void {
    // whereNumber() (WhereKind::Number) is the author's stated intent and must win even when the
    // model's key metadata says string+uuid.
    $descriptor = boundDescriptor(
        new RouteModelBinding(Article::class, 'id', 'string', 'uuid'),
        whereKind: WhereKind::Number,
    );

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);

    expect($parameter->schema->type)
        ->toBe('integer')
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

    expect($parameter->required)
        ->toBeTrue()
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
        enumCases: $cases,
        modelBinding: null,
    );
}

it(
    'refs a string-backed enum binding to a shared string-enum component, without a where constraint',
    function (): void {
        $descriptor = enumDescriptor(ArticleStatus::class, ['draft', 'published']);

        [$parameter] = $this->extractor->extract([[$descriptor, null]]);
        $component = json_decode(
            json_encode(collect($this->registry->all())->firstWhere('schema', 'ArticleStatus')),
            true,
        );

        expect($parameter->schema->ref)
            ->toBe('#/components/schemas/ArticleStatus')
            ->and($component['type'])->toBe('string')
            ->and($component['enum'])->toBe(['draft', 'published']);
    },
);

it('refs an int-backed enum binding to a shared integer-enum component, without a where constraint', function (): void {
    $descriptor = enumDescriptor(PriorityLevel::class, ['1', '2', '3']);

    [$parameter] = $this->extractor->extract([[$descriptor, null]]);
    $component = json_decode(json_encode(collect($this->registry->all())->firstWhere('schema', 'PriorityLevel')), true);

    expect($parameter->schema->ref)
        ->toBe('#/components/schemas/PriorityLevel')
        ->and($component['type'])->toBe('integer')
        ->and($component['enum'])->toBe([1, 2, 3]);
});
