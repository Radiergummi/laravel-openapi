<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Support\Generator\NullableSchema;

use function Radiergummi\OpenApi\is_undefined;

uses()->group('openapi');

/*
 * NullableSchema derives the keyword split from the annotation object rather than from a
 * hand-maintained list, so nothing in `src/` enumerates the keywords any more. These tests are
 * what keeps that honest:
 *
 *   - the partition proves every member swagger-php exposes is *classified*;
 *   - the keyword matrix proves each one is classified *correctly*, by running it through the real
 *     code path and asserting which side of the `oneOf` it lands on.
 *
 * Both assert membership rather than equality: the supported swagger-php range is
 * `^5.8 || ^6.1.2`, and the two majors do not expose an identical property set. A keyword the
 * installed version lacks must not fail; a member neither bucket knows about must.
 */
final class SchemaKeywordPartition
{
    /** Keywords documenting the field as a whole. They stay on the outer node of a split schema. */
    public const array DOCUMENTATION = [
        'default',
        'deprecated',
        'description',
        'example',
        'examples',
        'externalDocs',
        'nullable',
        'readOnly',
        'title',
        'writeOnly',
        'x',
        'xml',
    ];

    /** Keywords constraining the value. They move into the inner schema of a split schema. */
    public const array CONSTRAINTS = [
        'additionalItems',
        'additionalProperties',
        'allOf',
        'anyOf',
        'collectionFormat',
        'const',
        'contains',
        'contentEncoding',
        'contentMediaType',
        'dependencies',
        'discriminator',
        'enum',
        'exclusiveMaximum',
        'exclusiveMinimum',
        'format',
        'items',
        'maxItems',
        'maxLength',
        'maxProperties',
        'maximum',
        'minItems',
        'minLength',
        'minProperties',
        'minimum',
        'multipleOf',
        'not',
        'oneOf',
        'pattern',
        'patternProperties',
        'properties',
        'propertyNames',
        'ref',
        'required',
        'type',
        'unevaluatedProperties',
        'uniqueItems',
    ];

    /** swagger-php carriers for attached annotations. Never JSON-Schema keywords, never touched. */
    public const array CARRIERS = ['attachables', 'encoding'];

    /** Component-key members that identify the schema rather than describe it. */
    public const array IDENTITY = ['property', 'schema'];

    /**
     * Every public member the installed swagger-php exposes on a schema annotation. `OA\Property`
     * is included because it is what the request-body and Data-class paths actually pass in, and
     * it carries two members `OA\Schema` does not.
     *
     * @return list<string>
     */
    public static function reflectedMembers(): array
    {
        $members = [];

        foreach ([OA\Schema::class, OA\Property::class] as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $members[$property->getName()] = true;
            }
        }

        return array_keys($members);
    }
}

// region Partition: every member swagger-php exposes is classified

it('classifies every schema member swagger-php exposes', function (): void {
    $unclassified = array_values(array_filter(
        SchemaKeywordPartition::reflectedMembers(),
        static fn(string $member): bool => $member[0] !== '_'
            && !in_array($member, SchemaKeywordPartition::DOCUMENTATION, strict: true)
            && !in_array($member, SchemaKeywordPartition::CONSTRAINTS, strict: true)
            && !in_array($member, SchemaKeywordPartition::CARRIERS, strict: true)
            && !in_array($member, SchemaKeywordPartition::IDENTITY, strict: true),
    ));

    expect($unclassified)->toBe([], sprintf(
        'swagger-php exposes schema member(s) [%s] no bucket claims. Decide whether each documents '
        . 'the field (outer) or constrains it (inner) before nullable wrapping strands it silently.',
        implode(', ', $unclassified),
    ));
});

it('puts each classified member in exactly one bucket', function (): void {
    $duplicates = array_keys(array_filter(
        array_count_values([
            ...SchemaKeywordPartition::DOCUMENTATION,
            ...SchemaKeywordPartition::CONSTRAINTS,
            ...SchemaKeywordPartition::CARRIERS,
            ...SchemaKeywordPartition::IDENTITY,
        ]),
        static fn(int $occurrences): bool => $occurrences > 1,
    ));

    expect($duplicates)->toBe([]);
});

it('pins the deny-list the implementation uses to the partition above', function (): void {
    $constants = (new ReflectionClass(NullableSchema::class))->getConstants();

    expect($constants['DOCUMENTATION_KEYWORDS'])
        ->toBe(SchemaKeywordPartition::DOCUMENTATION)
        ->and($constants['ANNOTATION_CARRIERS'])->toBe(SchemaKeywordPartition::CARRIERS);
});

// endregion

// region Keyword matrix: each member lands on the side its classification claims

dataset('schema keywords', static function (): iterable {
    foreach (SchemaKeywordPartition::reflectedMembers() as $member) {
        if ($member[0] === '_' || in_array($member, SchemaKeywordPartition::IDENTITY, strict: true)) {
            continue;
        }

        yield $member => [
            $member,
            in_array($member, SchemaKeywordPartition::CONSTRAINTS, strict: true),
        ];
    }
});

/**
 * Reads a member that the inner schema may not declare at all: `encoding` exists on `OA\Property`
 * but not on the plain `OA\Schema` the split creates.
 */
function readSchemaMember(OA\Schema $schema, string $member): mixed
{
    return property_exists($schema, $member) ? $schema->{$member} : Generator::UNDEFINED;
}

/**
 * Asserts the partition for one keyword against an already-split schema.
 */
function expectKeywordSide(OA\Schema $outer, OA\Schema $inner, string $keyword, bool $movesInward): void
{
    if (!$movesInward) {
        expect(readSchemaMember($outer, $keyword))
            ->toBe('sentinel', "'{$keyword}' should stay on the outer node")
            ->and(is_undefined(readSchemaMember($inner, $keyword)))
            ->toBeTrue("'{$keyword}' should not be copied into the non-null branch");

        return;
    }

    expect(readSchemaMember($inner, $keyword))
        ->toBe('sentinel', "'{$keyword}' should constrain the non-null branch");

    // `oneOf` is the slot the wrapper itself occupies, so it is replaced rather than cleared.
    if ($keyword === 'oneOf') {
        expect($outer->oneOf)->toHaveCount(2);

        return;
    }

    expect(is_undefined(readSchemaMember($outer, $keyword)))
        ->toBeTrue("'{$keyword}' should be cleared from the outer node");
}

it('applyTo() moves each keyword to the side its classification claims', function (
    string $keyword,
    bool $movesInward,
): void {
    // An object type forces the oneOf split, the only branch that partitions keywords.
    $target = new OA\Property(['property' => 'field', 'type' => 'object']);
    $target->{$keyword} = 'sentinel';

    NullableSchema::applyTo($target);

    expect($target->oneOf)->toHaveCount(2);

    /** @var OA\Schema $inner */
    $inner = $target->oneOf[0];

    expectKeywordSide($target, $inner, $keyword, $movesInward);

    // The component key identifies the outer node and never migrates into the wrapper.
    expect($target->property)
        ->toBe('field')
        ->and(is_undefined(readSchemaMember($inner, 'property')))->toBeTrue();
})->with('schema keywords');

it('wrap() partitions each keyword identically and leaves the input alone', function (
    string $keyword,
    bool $movesInward,
): void {
    $schema = new OA\Property(['property' => 'field', 'type' => 'object']);
    $schema->{$keyword} = 'sentinel';

    $result = NullableSchema::wrap($schema);

    /** @var OA\Schema $inner */
    $inner = $result->oneOf[0];

    expectKeywordSide($result, $inner, $keyword, $movesInward);

    expect($schema->{$keyword})
        ->toBe('sentinel')
        ->and($schema->type)->toBe($keyword === 'type' ? 'sentinel' : 'object')
        ->and(is_undefined($schema->oneOf))->toBe($keyword !== 'oneOf');
})->with('schema keywords');

// endregion
