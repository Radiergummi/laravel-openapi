<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\FieldDescriptionMissing;
use Radiergummi\OpenApi\Lint\Rules\SchemaCompositeFieldsUninspected;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeBuilder;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeWalker;
use Radiergummi\OpenApi\Lint\TreeIndex;

uses()->group('openapi', 'lint');

/**
 * Build a document whose only component schema carries the given properties, then run the given
 * rules over it and return the findings.
 *
 * @param list<OA\Property>                             $properties
 * @param list<Radiergummi\OpenApi\Contracts\Lint\Rule> $rules
 *
 * @return list<Finding>
 */
function compositeFieldFindings(array $properties, array $rules): array
{
    $schema = new OA\Schema([
        'schema' => 'Thing',
        'description' => 'A thing.',
        'type' => 'object',
        'properties' => $properties,
    ]);

    $document = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 't', 'version' => '1']),
        'paths' => [],
        'components' => new OA\Components(['schemas' => [$schema]]),
    ]);

    $builder = new SpecTreeBuilder();
    $api = $builder->build($document, []);
    $index = TreeIndex::build($api, $document, array_map(static fn($r) => $r->id(), $rules), []);
    $context = new LintContext(api: $api, index: $index, rawSpec: $document, actionDescriptors: [], suppressions: []);

    return iterator_to_array(
        new SpecTreeWalker($rules)->walk($api, $context),
        preserve_keys: false,
    );
}

it('unwraps the nullable oneOf shape so field rules inspect the concrete branch', function (): void {
    // oneOf: [{type: string}, {type: null}] — the standard 3.1 nullable encoding, with no
    // description. Before unwrapping, this produced zero field nodes and field.description-missing
    // stayed silent.
    $findings = compositeFieldFindings(
        properties: [
            new OA\Property([
                'property' => 'nickname',
                'oneOf' => [
                    new OA\Schema(['type' => 'string']),
                    new OA\Schema(['type' => 'null']),
                ],
            ]),
        ],
        rules: [new FieldDescriptionMissing(), new SchemaCompositeFieldsUninspected()],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.description-missing')
        ->and($findings[0]->message)->toContain('nickname');
});

it('emits exactly one composite-fields-uninspected finding for a genuine anyOf union', function (): void {
    $findings = compositeFieldFindings(
        properties: [
            new OA\Property([
                'property' => 'payload',
                'description' => 'Either shape.',
                'anyOf' => [
                    new OA\Schema(['ref' => '#/components/schemas/A']),
                    new OA\Schema(['ref' => '#/components/schemas/B']),
                ],
            ]),
        ],
        rules: [new SchemaCompositeFieldsUninspected()],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.composite-fields-uninspected')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent)
        ->and($findings[0]->message)->toContain('payload');
});

it('does not flag the nullable shape as a composite union', function (): void {
    $findings = compositeFieldFindings(
        properties: [
            new OA\Property([
                'property' => 'avatar',
                'description' => 'Avatar url.',
                'oneOf' => [
                    new OA\Schema(['ref' => '#/components/schemas/Image']),
                    new OA\Schema(['type' => 'null']),
                ],
            ]),
        ],
        rules: [new SchemaCompositeFieldsUninspected()],
    );

    expect($findings)->toBe([]);
});

it('does not crash on an empty oneOf and emits no finding', function (): void {
    $findings = compositeFieldFindings(
        properties: [
            new OA\Property([
                'property' => 'weird',
                'description' => 'Weird.',
                'oneOf' => [],
            ]),
        ],
        rules: [new SchemaCompositeFieldsUninspected()],
    );

    expect($findings)->toBe([]);
});
