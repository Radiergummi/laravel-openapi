<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\InferenceRetention;
use Radiergummi\OpenApi\Support\Provenance\SchemaProvenance;
use Radiergummi\OpenApi\Tests\Fixtures\Retention\RetentionChild;
use Radiergummi\OpenApi\Tests\Fixtures\Retention\RetentionParent;
use Radiergummi\OpenApi\Tests\Fixtures\Retention\RetentionStray;
use Radiergummi\OpenApi\Tests\Fixtures\Retention\RetentionSubject;

uses()->group('openapi');

// region Helpers

/**
 * A counting factory producing a schema of the given shape; increments `$calls` per invocation.
 */
function countingSchemaFactory(array $properties, int &$calls): Closure
{
    return function () use ($properties, &$calls): OA\Schema {
        $calls++;

        return new OA\Schema($properties);
    };
}

/** The `$ref` of a named property on a schema, or null. */
function propertyRef(OA\Schema $schema, string $property): ?string
{
    foreach (is_array($schema->properties) ? $schema->properties : [] as $candidate) {
        if ((string) $candidate->property === $property && is_string($candidate->ref)) {
            return $candidate->ref;
        }
    }

    return null;
}

// endregion

it('does not re-invoke a rival factory when retention is off', function (): void {
    $registry = new ComponentSchemaRegistry(new InferenceRetention());
    $calls = 0;
    $factory = countingSchemaFactory(['type' => 'object'], $calls);

    $registry->buildOnce(RetentionSubject::class, $factory, new SchemaProvenance('ProducerA'));
    $registry->buildOnce(RetentionSubject::class, $factory, new SchemaProvenance('ProducerB'));

    expect($calls)->toBe(1);
});

it('re-invokes a rival factory and stashes the inferred view when retention is on', function (): void {
    $retention = new InferenceRetention();
    $retention->enable();
    $registry = new ComponentSchemaRegistry($retention);
    $calls = 0;
    $factory = countingSchemaFactory(['type' => 'object'], $calls);

    $key = $registry->buildOnce(RetentionSubject::class, $factory, new SchemaProvenance('ProducerA'));
    $registry->buildOnce(RetentionSubject::class, $factory, new SchemaProvenance('ProducerB'));

    $winner = $registry->schemaForKey($key);
    $stashed = $retention->inferredSchemas()[$key] ?? null;

    // The rival factory runs a second time; the winner stays and the rival view is stashed.
    expect($calls)->toBe(2)
        ->and($retention->hasInferredSchema($key))->toBeTrue()
        ->and($winner?->jsonSerialize())->toEqual($stashed?->jsonSerialize());
});

it('keeps the primary schema byte-identical whether or not retention is on', function (): void {
    $off = new ComponentSchemaRegistry(new InferenceRetention());
    $calls = 0;
    $keyOff = $off->buildOnce(RetentionSubject::class, countingSchemaFactory(['type' => 'object'], $calls), new SchemaProvenance('A'));
    $off->buildOnce(RetentionSubject::class, countingSchemaFactory(['type' => 'object'], $calls), new SchemaProvenance('B'));

    $retention = new InferenceRetention();
    $retention->enable();
    $on = new ComponentSchemaRegistry($retention);
    $keyOn = $on->buildOnce(RetentionSubject::class, countingSchemaFactory(['type' => 'object'], $calls), new SchemaProvenance('A'));
    $on->buildOnce(RetentionSubject::class, countingSchemaFactory(['type' => 'object'], $calls), new SchemaProvenance('B'));

    expect(json_encode($on->schemaForKey($keyOn)?->jsonSerialize()))
        ->toBe(json_encode($off->schemaForKey($keyOff)?->jsonSerialize()));
});

it('stashes a contested schema whose nested $ref names a second contested class (by-name parity)', function (): void {
    $retention = new InferenceRetention();
    $retention->enable();
    $registry = new ComponentSchemaRegistry($retention);

    // The parent factory references a second class, itself built via buildOnce, so both are contested.
    $parentFactory = function () use ($registry): OA\Schema {
        $childKey = $registry->buildOnce(
            RetentionChild::class,
            static fn(): OA\Schema => new OA\Schema(['type' => 'object']),
            new SchemaProvenance('ProducerA'),
        );

        return new OA\Schema([
            'type' => 'object',
            'properties' => [
                new OA\Property(['property' => 'child', 'ref' => ComponentReference::pointer($childKey)]),
            ],
        ]);
    };

    $parentKey = $registry->buildOnce(RetentionParent::class, $parentFactory, new SchemaProvenance('ProducerA'));
    $childKey = (string) $registry->keyFor(RetentionChild::class);

    // A rival producer reaches the parent; its factory re-runs against the already-owned child.
    $registry->buildOnce(RetentionParent::class, $parentFactory, new SchemaProvenance('ProducerB'));

    $stashedParent = $retention->inferredSchemas()[$parentKey];

    // Comparison is by $ref name: the stashed inferred parent's leaf ref points at the child's key,
    // identical to a fresh all-inferred build even though the leaf resolved to an existing winner.
    expect($retention->hasInferredSchema($parentKey))->toBeTrue()
        ->and(propertyRef($stashedParent, 'child'))->toBe(ComponentReference::pointer($childKey));
});

it('rolls back a nested class a rival factory builds, leaving the winner document untouched', function (): void {
    $retention = new InferenceRetention();
    $retention->enable();
    $registry = new ComponentSchemaRegistry($retention);

    $key = $registry->buildOnce(
        RetentionSubject::class,
        static fn(): OA\Schema => new OA\Schema(['type' => 'object']),
        new SchemaProvenance('ProducerA'),
    );

    $before = $registry->componentClassMap();

    // The rival factory builds a nested class that was not registered during the winner build.
    $registry->buildOnce(
        RetentionSubject::class,
        function () use ($registry): OA\Schema {
            $registry->buildOnce(
                RetentionStray::class,
                static fn(): OA\Schema => new OA\Schema(['type' => 'object']),
                new SchemaProvenance('ProducerB'),
            );

            return new OA\Schema(['type' => 'object']);
        },
        new SchemaProvenance('ProducerB'),
    );

    // The rival ran (its view is stashed) but its nested build left no trace in the winner document.
    expect($retention->hasInferredSchema($key))->toBeTrue()
        ->and($registry->componentClassMap())->toBe($before)
        ->and($registry->keyFor(RetentionStray::class))->toBeNull()
        ->and($registry->schemaForKey('RetentionStray'))->toBeNull();
});

it('warns when a rival producer builds a different schema for an owned component', function (): void {
    $retention = new InferenceRetention();
    $retention->enable();
    $logger = recordingLogger();
    $registry = new ComponentSchemaRegistry($retention, $logger);
    $calls = 0;

    $registry->buildOnce(RetentionSubject::class, countingSchemaFactory(['type' => 'object'], $calls), new SchemaProvenance('ProducerA'));
    $registry->buildOnce(RetentionSubject::class, countingSchemaFactory(['type' => 'string'], $calls), new SchemaProvenance('ProducerB'));

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('different schema');
});

it('stays silent when a rival producer builds an identical schema', function (): void {
    $retention = new InferenceRetention();
    $retention->enable();
    $logger = recordingLogger();
    $registry = new ComponentSchemaRegistry($retention, $logger);
    $calls = 0;

    $registry->buildOnce(RetentionSubject::class, countingSchemaFactory(['type' => 'object'], $calls), new SchemaProvenance('ProducerA'));
    $registry->buildOnce(RetentionSubject::class, countingSchemaFactory(['type' => 'object'], $calls), new SchemaProvenance('ProducerB'));

    expect($logger->records)->toBe([]);
});
