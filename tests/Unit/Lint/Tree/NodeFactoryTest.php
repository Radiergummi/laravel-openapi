<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Lint\Tree\NodeFactory;

uses()->group('openapi', 'lint');

describe('exampleNode', function (): void {
    it('maps every defined field onto the node', function (): void {
        $raw = new OA\Examples([
            'example' => 'first',
            'value' => ['id' => 1],
            'summary' => 'A summary',
            'description' => 'A description',
        ]);

        $node = NodeFactory::exampleNode($raw);

        expect($node->name)
            ->toBe('first')
            ->and($node->value)->toBe(['id' => 1])
            ->and($node->summary)->toBe('A summary')
            ->and($node->description)->toBe('A description')
            ->and($node->raw)->toBe($raw);
    });

    it('nulls out undefined fields rather than leaking the UNDEFINED sentinel', function (): void {
        $node = NodeFactory::exampleNode(new OA\Examples([]));

        expect($node->name)
            ->toBeNull()
            ->and($node->value)->toBeNull()
            ->and($node->summary)->toBeNull()
            ->and($node->description)->toBeNull();
    });
});

describe('examplesFromParameter / examplesFromSchema', function (): void {
    it('returns an empty list when no examples are defined', function (): void {
        expect(NodeFactory::examplesFromParameter(new OA\Parameter(['name' => 'q', 'in' => 'query'])))
            ->toBe([])
            ->and(NodeFactory::examplesFromSchema(new OA\Schema(['type' => 'string'])))->toBe([]);
    });

    it('builds one ExampleNode per OA\\Examples entry on a parameter', function (): void {
        $parameter = new OA\Parameter([
            'name' => 'q',
            'in' => 'query',
            'examples' => [
                'one' => new OA\Examples(['example' => 'one', 'value' => 1]),
                'two' => new OA\Examples(['example' => 'two', 'value' => 2]),
            ],
        ]);

        $nodes = NodeFactory::examplesFromParameter($parameter);

        expect($nodes)
            ->toHaveCount(2)
            ->and($nodes[0]->name)->toBe('one')
            ->and($nodes[1]->name)->toBe('two');
    });

    it('skips undefined and non-OA\\Examples entries', function (): void {
        $schema = new OA\Schema([
            'type' => 'string',
            'examples' => [
                'valid' => new OA\Examples(['example' => 'valid', 'value' => 'x']),
                'sentinel' => Generator::UNDEFINED,
                'bogus' => 'not-an-example-object',
            ],
        ]);

        $nodes = NodeFactory::examplesFromSchema($schema);

        expect($nodes)
            ->toHaveCount(1)
            ->and($nodes[0]->name)->toBe('valid');
    });
});

describe('header', function (): void {
    it('maps a fully-populated header', function (): void {
        $raw = new OA\Header([
            'header' => 'X-Rate-Limit',
            'schema' => new OA\Schema(['type' => 'integer']),
            'description' => 'Requests left',
            'required' => true,
        ]);

        $node = NodeFactory::header($raw);

        expect($node->name)
            ->toBe('X-Rate-Limit')
            ->and($node->schema)->toBe('integer')
            ->and($node->description)->toBe('Requests left')
            ->and($node->required)->toBeTrue()
            ->and($node->raw)->toBe($raw);
    });

    it('falls back to "(unknown)" and safe defaults when fields are undefined', function (): void {
        $node = NodeFactory::header(new OA\Header([]));

        expect($node->name)
            ->toBe('(unknown)')
            ->and($node->schema)->toBeNull()
            ->and($node->description)->toBeNull()
            ->and($node->required)->toBeFalse();
    });

    it('treats a non-true required value as not required', function (): void {
        $node = NodeFactory::header(new OA\Header(['header' => 'X-Thing', 'required' => false]));

        expect($node->required)->toBeFalse();
    });
});

describe('link', function (): void {
    it('maps a fully-populated link, keeping only string parameter mappings', function (): void {
        $raw = new OA\Link([
            'link' => 'GetUserById',
            'operationId' => 'getUser',
            'operationRef' => '#/paths/~1users~1{id}/get',
            'description' => 'Follow to the user',
            'parameters' => [
                'id' => '$response.body#/id',
                'bogus' => 42,
            ],
        ]);

        $node = NodeFactory::link($raw);

        expect($node->name)
            ->toBe('GetUserById')
            ->and($node->operationId)->toBe('getUser')
            ->and($node->operationRef)->toBe('#/paths/~1users~1{id}/get')
            ->and($node->description)->toBe('Follow to the user')
            ->and($node->parameters)->toBe(['id' => '$response.body#/id'])
            ->and($node->raw)->toBe($raw);
    });

    it('falls back to "(unnamed)" and empty parameters when fields are undefined', function (): void {
        $node = NodeFactory::link(new OA\Link([]));

        expect($node->name)
            ->toBe('(unnamed)')
            ->and($node->operationId)->toBeNull()
            ->and($node->operationRef)->toBeNull()
            ->and($node->description)->toBeNull()
            ->and($node->parameters)->toBe([]);
    });
});
