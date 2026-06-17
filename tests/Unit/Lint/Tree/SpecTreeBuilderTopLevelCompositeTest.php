<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\SchemaCompositeFieldsUninspected;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeBuilder;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeWalker;
use Radiergummi\OpenApi\Lint\TreeIndex;

uses()->group('openapi', 'lint');

/**
 * Run the given rules over a document and return the findings.
 *
 * @param list<Radiergummi\OpenApi\Contracts\Lint\Rule> $rules
 *
 * @return list<Finding>
 */
function topLevelCompositeFindings(OA\OpenApi $document, array $rules): array
{
    $builder = new SpecTreeBuilder();
    $api = $builder->build($document, []);
    $index = TreeIndex::build($api, $document, array_map(static fn($r) => $r->id(), $rules), []);
    $context = new LintContext(api: $api, index: $index, rawSpec: $document, actionDescriptors: [], suppressions: []);

    return iterator_to_array(
        new SpecTreeWalker($rules)->walk($api, $context),
        preserve_keys: false,
    );
}

/**
 * A document whose only component schema is the given schema.
 */
function documentWithComponentSchema(OA\Schema $schema): OA\OpenApi
{
    return new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 't', 'version' => '1']),
        'paths' => [],
        'components' => new OA\Components(['schemas' => [$schema]]),
    ]);
}

/**
 * A document with a single GET operation whose 200 response body is the given schema.
 */
function documentWithResponseBody(OA\Schema $schema): OA\OpenApi
{
    $operation = new OA\Get([
        'path' => '/things',
        'responses' => [
            new OA\Response([
                'response' => 200,
                'description' => 'A thing.',
                'content' => [
                    new OA\MediaType(['mediaType' => 'application/json', 'schema' => $schema]),
                ],
            ]),
        ],
    ]);

    return new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 't', 'version' => '1']),
        'paths' => [new OA\PathItem(['path' => '/things', 'get' => $operation])],
    ]);
}

/**
 * A document with a single POST operation whose request body is the given schema.
 */
function documentWithRequestBody(OA\Schema $schema): OA\OpenApi
{
    $operation = new OA\Post([
        'path' => '/things',
        'requestBody' => new OA\RequestBody([
            'description' => 'A thing.',
            'required' => true,
            'content' => [
                new OA\MediaType(['mediaType' => 'application/json', 'schema' => $schema]),
            ],
        ]),
        'responses' => [
            new OA\Response(['response' => 204, 'description' => 'No content.']),
        ],
    ]);

    return new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 't', 'version' => '1']),
        'paths' => [new OA\PathItem(['path' => '/things', 'post' => $operation])],
    ]);
}

it('flags a top-level bare anyOf component schema as uninspected', function (): void {
    $schema = new OA\Schema([
        'schema' => 'Thing',
        'description' => 'A thing.',
        'anyOf' => [
            new OA\Schema(['ref' => '#/components/schemas/A']),
            new OA\Schema(['ref' => '#/components/schemas/B']),
        ],
    ]);

    $findings = topLevelCompositeFindings(
        documentWithComponentSchema($schema),
        [new SchemaCompositeFieldsUninspected()],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.composite-fields-uninspected')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent)
        ->and($findings[0]->message)->toContain('Thing');
});

it('flags a top-level bare oneOf response body as uninspected', function (): void {
    $schema = new OA\Schema([
        'oneOf' => [
            new OA\Schema(['ref' => '#/components/schemas/A']),
            new OA\Schema(['ref' => '#/components/schemas/B']),
        ],
    ]);

    $findings = topLevelCompositeFindings(
        documentWithResponseBody($schema),
        [new SchemaCompositeFieldsUninspected()],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.composite-fields-uninspected')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent);
});

it('flags a top-level bare anyOf request body as uninspected', function (): void {
    $schema = new OA\Schema([
        'anyOf' => [
            new OA\Schema(['ref' => '#/components/schemas/A']),
            new OA\Schema(['ref' => '#/components/schemas/B']),
        ],
    ]);

    $findings = topLevelCompositeFindings(
        documentWithRequestBody($schema),
        [new SchemaCompositeFieldsUninspected()],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.composite-fields-uninspected')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent);
});

it('does not flag the top-level nullable shape as a composite union', function (): void {
    $schema = new OA\Schema([
        'schema' => 'Thing',
        'description' => 'A thing.',
        'oneOf' => [
            new OA\Schema(['ref' => '#/components/schemas/Image']),
            new OA\Schema(['type' => 'null']),
        ],
    ]);

    $findings = topLevelCompositeFindings(
        documentWithComponentSchema($schema),
        [new SchemaCompositeFieldsUninspected()],
    );

    expect($findings)->toBe([]);
});

it('does not flag an ordinary object component schema', function (): void {
    $schema = new OA\Schema([
        'schema' => 'Thing',
        'description' => 'A thing.',
        'type' => 'object',
        'properties' => [
            new OA\Property(['property' => 'name', 'type' => 'string', 'description' => 'Name.']),
        ],
    ]);

    $findings = topLevelCompositeFindings(
        documentWithComponentSchema($schema),
        [new SchemaCompositeFieldsUninspected()],
    );

    expect($findings)->toBe([]);
});
