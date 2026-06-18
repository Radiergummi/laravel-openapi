<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaEquivalence;

uses()->group('openapi');

function schemaEquivalence(): SchemaEquivalence
{
    return new SchemaEquivalence();
}

/*
 * The rule fires when inference (`$control`) subsumes the authored annotation, i.e., reproduces
 * everything the author wrote and possibly more. `subsumes($broader, $narrower)` asks whether
 * `$narrower` is structurally contained in `$broader`.
 */
it('subsumes an identical schema', function (): void {
    $a = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'id', 'type' => 'integer']),
        new OA\Property(['property' => 'name', 'type' => 'string']),
    ]]);
    $b = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'id', 'type' => 'integer']),
        new OA\Property(['property' => 'name', 'type' => 'string']),
    ]]);

    expect(schemaEquivalence()->subsumes($a, $b))->toBeTrue();
});

it('subsumes regardless of property and required-member order', function (): void {
    $broader = new OA\Schema(['type' => 'object', 'required' => ['id', 'name'], 'properties' => [
        new OA\Property(['property' => 'id', 'type' => 'integer']),
        new OA\Property(['property' => 'name', 'type' => 'string']),
    ]]);
    $narrower = new OA\Schema(['type' => 'object', 'required' => ['name', 'id'], 'properties' => [
        new OA\Property(['property' => 'name', 'type' => 'string']),
        new OA\Property(['property' => 'id', 'type' => 'integer']),
    ]]);

    expect(schemaEquivalence()->subsumes($broader, $narrower))->toBeTrue();
});

it('subsumes when inference enriches the authored schema (extra example, extra property)', function (): void {
    // Inference adds an `example` to a property and discovers an extra `email` property the
    // author never annotated — the authored schema is still fully contained.
    $control = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'id', 'type' => 'integer', 'example' => 32]),
        new OA\Property(['property' => 'name', 'type' => 'string']),
        new OA\Property(['property' => 'email', 'type' => 'string']),
    ]]);
    $authored = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'id', 'type' => 'integer']),
        new OA\Property(['property' => 'name', 'type' => 'string']),
    ]]);

    expect(schemaEquivalence()->subsumes($control, $authored))->toBeTrue();
});

it('does not subsume when the author carries information inference lacks', function (): void {
    // The author wrote a description inference does not produce — essential, must be kept.
    $control = new OA\Schema(['type' => 'string']);
    $authored = new OA\Schema(['type' => 'string', 'description' => 'The user email']);

    expect(schemaEquivalence()->subsumes($control, $authored))->toBeFalse();
});

it('does not subsume a genuine restriction the author added', function (): void {
    // `additionalProperties: false` is a key inference never emits, so the authored schema is
    // not contained — the restriction is preserved.
    $control = new OA\Schema(['type' => 'object']);
    $authored = new OA\Schema(['type' => 'object', 'additionalProperties' => false]);

    expect(schemaEquivalence()->subsumes($control, $authored))->toBeFalse();
});

it('does not subsume a differing scalar type', function (): void {
    expect(schemaEquivalence()->subsumes(new OA\Schema(['type' => 'string']), new OA\Schema(['type' => 'integer'])))
        ->toBeFalse();
});

it('ignores unset (UNDEFINED) properties', function (): void {
    $broader = new OA\Schema(['type' => 'string']);
    $narrower = new OA\Schema(['type' => 'string', 'format' => OpenApi\Generator::UNDEFINED]);

    expect(schemaEquivalence()->subsumes($broader, $narrower))->toBeTrue();
});

it('treats null against a present annotation as not subsumed', function (): void {
    expect(schemaEquivalence()->subsumes(null, new OA\Schema(['type' => 'string'])))->toBeFalse()
        ->and(schemaEquivalence()->subsumes(null, null))->toBeTrue();
});

it('does not subsume when a required member is absent from the broader side', function (): void {
    // List containment: the narrower `required` carries a member ('email') no broader element matches.
    $broader = new OA\Schema(['type' => 'object', 'required' => ['id']]);
    $narrower = new OA\Schema(['type' => 'object', 'required' => ['id', 'email']]);

    expect(schemaEquivalence()->subsumes($broader, $narrower))->toBeFalse();
});

it('drops UNDEFINED elements inside a list before comparing', function (): void {
    // A list with an UNDEFINED hole still subsumes once normalized down to its defined members.
    $broader = new OA\Schema(['type' => 'string', 'enum' => ['a', 'b']]);
    $narrower = new OA\Schema(['type' => 'string', 'enum' => ['a', OpenApi\Generator::UNDEFINED, 'b']]);

    expect(schemaEquivalence()->subsumes($broader, $narrower))->toBeTrue();
});

// region $ref-by-name following

use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaRefResolver;

/**
 * A schema with one property referencing `$refName`, mirroring a convention/authored component graph.
 */
function refParentSchema(string $refName): OA\Schema
{
    return new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'child', 'ref' => "#/components/schemas/{$refName}"]),
    ]]);
}

/**
 * @param array<string, ?OA\Schema> $inferred
 * @param array<string, ?OA\Schema> $authored
 */
function refResolver(array $inferred, array $authored): SchemaRefResolver
{
    return new class ($inferred, $authored) implements SchemaRefResolver {
        /**
         * @param array<string, ?OA\Schema> $inferred
         * @param array<string, ?OA\Schema> $authored
         */
        public function __construct(private array $inferred, private array $authored) {}

        public function resolveInferred(string $name): ?OA\Schema
        {
            return $this->inferred[$name] ?? null;
        }

        public function resolveAuthored(string $name): ?OA\Schema
        {
            return $this->authored[$name] ?? null;
        }
    };
}

it('subsumes a differing $ref name whose target graphs are equivalent', function (): void {
    $broader = refParentSchema('RefChildData');
    $narrower = refParentSchema('RefChild');
    $resolver = refResolver(
        inferred: ['RefChildData' => new OA\Schema(['type' => 'object', 'properties' => [
            new OA\Property(['property' => 'label', 'type' => 'string']),
        ]])],
        authored: ['RefChild' => new OA\Schema(['type' => 'object', 'properties' => [
            new OA\Property(['property' => 'label', 'type' => 'string']),
        ]])],
    );

    expect(new SchemaEquivalence($resolver)->subsumes($broader, $narrower))->toBeTrue();
});

it('does not subsume when the referenced target genuinely differs', function (): void {
    $resolver = refResolver(
        inferred: ['RefChildData' => new OA\Schema(['type' => 'object', 'properties' => [
            new OA\Property(['property' => 'label', 'type' => 'string']),
        ]])],
        authored: ['RefChild' => new OA\Schema(['type' => 'object', 'properties' => [
            new OA\Property(['property' => 'label', 'type' => 'integer']),
        ]])],
    );

    expect(new SchemaEquivalence($resolver)->subsumes(refParentSchema('RefChildData'), refParentSchema('RefChild')))
        ->toBeFalse();
});

it('does not subsume when either side ref is unresolvable (conservative)', function (): void {
    $onlyInferred = refResolver(
        inferred: ['RefChildData' => new OA\Schema(['type' => 'object'])],
        authored: [],
    );

    expect(new SchemaEquivalence($onlyInferred)->subsumes(refParentSchema('RefChildData'), refParentSchema('RefChild')))
        ->toBeFalse();
});

it('does not subsume a differing $ref with no resolver at all (today path unchanged)', function (): void {
    expect(schemaEquivalence()->subsumes(refParentSchema('RefChildData'), refParentSchema('RefChild')))
        ->toBeFalse();
});

it('does not consult the resolver when the $ref names are equal (short-circuit)', function (): void {
    $spy = new class () implements SchemaRefResolver {
        public function resolveInferred(string $name): ?OA\Schema
        {
            throw new RuntimeException('resolver consulted on equal-ref short-circuit');
        }

        public function resolveAuthored(string $name): ?OA\Schema
        {
            throw new RuntimeException('resolver consulted on equal-ref short-circuit');
        }
    };

    expect(new SchemaEquivalence($spy)->subsumes(refParentSchema('Same'), refParentSchema('Same')))
        ->toBeTrue();
});

it('terminates on a recursive (self-referential) $ref graph and subsumes', function (): void {
    // A.self -> $ref A on both sides; the cycle's content matches, so it subsumes without looping.
    $inferredA = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'self', 'ref' => '#/components/schemas/NodeData']),
    ]]);
    $authoredA = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'self', 'ref' => '#/components/schemas/Node']),
    ]]);
    $resolver = refResolver(
        inferred: ['NodeData' => $inferredA],
        authored: ['Node' => $authoredA],
    );

    expect(new SchemaEquivalence($resolver)->subsumes($inferredA, $authoredA))->toBeTrue();
});

it('does not subsume a recursive graph whose non-ref content differs', function (): void {
    $inferredA = new OA\Schema(['type' => 'object', 'title' => 'Node', 'properties' => [
        new OA\Property(['property' => 'self', 'ref' => '#/components/schemas/NodeData']),
    ]]);
    // The authored side adds a property inference does not carry, so containment fails despite the
    // cycle resolving.
    $authoredA = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'self', 'ref' => '#/components/schemas/Node']),
        new OA\Property(['property' => 'extra', 'type' => 'string']),
    ]]);
    $resolver = refResolver(
        inferred: ['NodeData' => $inferredA],
        authored: ['Node' => $authoredA],
    );

    expect(new SchemaEquivalence($resolver)->subsumes($inferredA, $authoredA))->toBeFalse();
});

it('terminates on a mutual (A↔B) cyclic $ref graph and subsumes', function (): void {
    // A.b -> B, B.a -> A on both sides; the visited-pair set accumulates two pairs before catching
    // the loop, so this exercises a different traversal than the self-reference case.
    $inferredA = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'b', 'ref' => '#/components/schemas/BData']),
    ]]);
    $inferredB = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'a', 'ref' => '#/components/schemas/AData']),
    ]]);
    $authoredA = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'b', 'ref' => '#/components/schemas/B']),
    ]]);
    $authoredB = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'a', 'ref' => '#/components/schemas/A']),
    ]]);
    $resolver = refResolver(
        inferred: ['AData' => $inferredA, 'BData' => $inferredB],
        authored: ['A' => $authoredA, 'B' => $authoredB],
    );

    expect(new SchemaEquivalence($resolver)->subsumes($inferredA, $authoredA))->toBeTrue();
});

it('does not subsume a mutual cyclic graph whose content differs on one node', function (): void {
    $inferredA = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'b', 'ref' => '#/components/schemas/BData']),
    ]]);
    $inferredB = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'a', 'ref' => '#/components/schemas/AData']),
    ]]);
    $authoredA = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'b', 'ref' => '#/components/schemas/B']),
    ]]);
    // B's authored target carries an extra property inference does not reproduce.
    $authoredB = new OA\Schema(['type' => 'object', 'properties' => [
        new OA\Property(['property' => 'a', 'ref' => '#/components/schemas/A']),
        new OA\Property(['property' => 'extra', 'type' => 'string']),
    ]]);
    $resolver = refResolver(
        inferred: ['AData' => $inferredA, 'BData' => $inferredB],
        authored: ['A' => $authoredA, 'B' => $authoredB],
    );

    expect(new SchemaEquivalence($resolver)->subsumes($inferredA, $authoredA))->toBeFalse();
});

// endregion
