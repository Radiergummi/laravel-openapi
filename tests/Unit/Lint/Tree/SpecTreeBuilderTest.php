<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Generator;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Core\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\Tree\SpecTreeBuilder;

uses()->group('openapi', 'lint', 'tree');

function makeMinimalSpec(): OA\OpenApi
{
    $context = new Context();
    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info([
            'title' => 'Test',
            'version' => '0.1',
            '_context' => $context,
        ]),
        '_context' => $context,
    ]);

    $response200 = new OA\Response(['_context' => $context]);
    $response200->response = 200;
    $response200->description = 'Success';

    $operation = new OA\Get(['_context' => $context]);
    $operation->operationId = 'getUsers';
    $operation->summary = 'Get users';
    $operation->description = 'Returns all users';
    $operation->tags = ['Users'];
    $operation->responses = [$response200];
    $operation->parameters = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $operation->requestBody = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $operation->security = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $operation->deprecated = false;

    $pathItem = new OA\PathItem(['_context' => $context]);
    $pathItem->path = '/api/v0/users';
    $pathItem->get = $operation;
    $pathItem->post = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->put = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->patch = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->delete = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->options = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->head = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->trace = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType

    $spec->paths = [$pathItem];
    $spec->components = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $spec->tags = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $spec->webhooks = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType

    return $spec;
}

function makeSpecWithComponent(): OA\OpenApi
{
    $spec = makeMinimalSpec();
    $context = new Context();

    $property = new OA\Property(['_context' => $context]);
    $property->property = 'name';
    $property->type = 'string';
    $property->description = 'User name';
    $property->example = 'John';
    $property->nullable = false;
    $property->format = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $property->enum = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $property->ref = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType

    $schema = new OA\Schema(['_context' => $context]);
    $schema->schema = 'User';
    $schema->description = 'A user resource';
    $schema->properties = [$property];
    $schema->required = ['name'];

    $components = new OA\Components(['_context' => $context]);
    $components->schemas = [$schema];
    $spec->components = $components;

    return $spec;
}

it('builds an ApiNode from a minimal spec', function (): void {
    $spec = makeMinimalSpec();
    $builder = new SpecTreeBuilder();

    $api = $builder->build($spec, []);

    expect($api)
        ->toBeInstanceOf(ApiNode::class)
        ->and($api->operations)
        ->toHaveCount(1)
        ->and($api->components)
        ->toHaveCount(0)
        ->and($api->webhooks)
        ->toHaveCount(0);
});

it('maps operation properties correctly', function (): void {
    $spec = makeMinimalSpec();
    $builder = new SpecTreeBuilder();

    $api = $builder->build($spec, []);
    $op = $api->operations[0];

    expect($op)
        ->toBeInstanceOf(OperationNode::class)
        ->and($op->pathUri)
        ->toBe('/api/v0/users')
        ->and($op->method)
        ->toBe('GET')
        ->and($op->operationId)
        ->toBe('getUsers')
        ->and($op->summary)
        ->toBe('Get users')
        ->and($op->description)
        ->toBe('Returns all users')
        ->and($op->tags)
        ->toBe(['Users'])
        ->and($op->deprecated)
        ->toBeFalse()
        ->and($op->webhook)
        ->toBeFalse();
});

it('links parent references correctly', function (): void {
    $spec = makeMinimalSpec();
    $builder = new SpecTreeBuilder();

    $api = $builder->build($spec, []);
    $op = $api->operations[0];

    expect($op->parent())->toBe($api);
});

it('extracts responses with status codes', function (): void {
    $spec = makeMinimalSpec();
    $builder = new SpecTreeBuilder();

    $api = $builder->build($spec, []);
    $op = $api->operations[0];

    expect($op->responses)
        ->toHaveCount(1)
        ->and($op->responses[0])
        ->toBeInstanceOf(ResponseNode::class)
        ->and($op->responses[0]->statusCode)
        ->toBe(200)
        ->and($op->responses[0]->description)
        ->toBe('Success')
        ->and($op->responses[0]->parent())
        ->toBe($op);
});

it('builds component schemas with fields', function (): void {
    $spec = makeSpecWithComponent();
    $builder = new SpecTreeBuilder();

    $api = $builder->build($spec, []);

    expect($api->components)
        ->toHaveCount(1)
        ->and($api->components[0])
        ->toBeInstanceOf(ComponentSchemaNode::class)
        ->and($api->components[0]->name)
        ->toBe('User')
        ->and($api->components[0]->description)
        ->toBe('A user resource')
        ->and($api->components[0]->fields)
        ->toHaveCount(1)
        ->and($api->components[0]->parent())
        ->toBe($api);
});

it('builds field nodes with correct properties', function (): void {
    $spec = makeSpecWithComponent();
    $builder = new SpecTreeBuilder();

    $api = $builder->build($spec, []);
    $field = $api->components[0]->fields[0];

    expect($field)
        ->toBeInstanceOf(FieldNode::class)
        ->and($field->name)
        ->toBe('name')
        ->and($field->type)
        ->toBe('string')
        ->and($field->required)
        ->toBeTrue()
        ->and($field->description)
        ->toBe('User name')
        ->and($field->example)
        ->toBe('John')
        ->and($field->parent())
        ->toBe($api->components[0]);
});

it('returns empty operations for a spec with no paths', function (): void {
    $context = new Context();
    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info([
            'title' => 'Test',
            'version' => '0.1',
            '_context' => $context,
        ]),
        '_context' => $context,
    ]);
    $spec->paths = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $spec->components = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $spec->tags = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $spec->webhooks = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType

    $builder = new SpecTreeBuilder();
    $api = $builder->build($spec, []);

    expect($api->operations)
        ->toBeEmpty()
        ->and($api->components)
        ->toBeEmpty()
        ->and($api->webhooks)
        ->toBeEmpty();
});

it('handles operations without descriptors gracefully', function (): void {
    $spec = makeMinimalSpec();
    $builder = new SpecTreeBuilder();

    $api = $builder->build($spec, []);
    $op = $api->operations[0];

    expect($op->descriptor)->toBeNull();
});

it('generates correct JSON Pointers for operations', function (): void {
    $spec = makeMinimalSpec();
    $builder = new SpecTreeBuilder();

    $api = $builder->build($spec, []);
    $op = $api->operations[0];

    expect($op->pointer())->toBe('#/paths/~1api~1v0~1users/get');
});

it('generates correct JSON Pointers for responses', function (): void {
    $spec = makeMinimalSpec();
    $builder = new SpecTreeBuilder();

    $api = $builder->build($spec, []);
    $response = $api->operations[0]->responses[0];

    expect($response->pointer())->toBe(
        '#/paths/~1api~1v0~1users/get/responses/200',
    );
});

it(
    'provides operation helpers for success and error responses',
    function (): void {
        $spec = makeMinimalSpec();

        // Add an error response
        $response400 = new OA\Response(['_context' => new Context()]);
        $response400->response = 400;
        $response400->description = 'Bad request';

        $spec->paths[0]->get->responses[] = $response400;

        $builder = new SpecTreeBuilder();
        $api = $builder->build($spec, []);
        $op = $api->operations[0];

        expect($op->successResponses())
            ->toHaveCount(1)
            ->and($op->errorResponses())
            ->toHaveCount(1)
            ->and($op->successResponses()[0]->statusCode)
            ->toBe(200)
            ->and($op->errorResponses()[0]->statusCode)
            ->toBe(400);
    },
);

it('populates WebhookNode description from the PathItem description field, not summary', function (): void {
    $context = new Context();

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info([
            'title' => 'Test',
            'version' => '0.1',
            '_context' => $context,
        ]),
        '_context' => $context,
    ]);
    $spec->paths = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $spec->components = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $spec->tags = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType

    $response200 = new OA\Response(['_context' => $context]);
    $response200->response = 200;
    $response200->description = 'OK';

    $post = new OA\Post(['_context' => $context]);
    $post->operationId = 'orderCreated';
    $post->responses = [$response200];
    $post->parameters = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $post->requestBody = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $post->security = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $post->deprecated = false;

    $pathItem = new OA\PathItem(['_context' => $context]);
    $pathItem->path = 'orderCreated';
    $pathItem->post = $post;
    $pathItem->get = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->put = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->patch = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->delete = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->options = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->head = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->trace = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->summary = 'Order summary';
    $pathItem->description = 'Fired when an order is created.';

    $spec->webhooks = ['orderCreated' => $pathItem]; // @phpstan-ignore assign.propertyType

    $builder = new SpecTreeBuilder();
    $api = $builder->build($spec, []);

    expect($api->webhooks)->toHaveCount(1);
    $webhook = $api->webhooks[0];

    // The description must come from $pathItem->description, not $pathItem->summary.
    expect($webhook->description)->toBe('Fired when an order is created.');
});

it('builds one WebhookNode per HTTP method for multi-method webhook path items', function (): void {
    $context = new Context();

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info([
            'title' => 'Test',
            'version' => '0.1',
            '_context' => $context,
        ]),
        '_context' => $context,
    ]);
    $spec->paths = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $spec->components = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $spec->tags = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType

    $response200 = new OA\Response(['_context' => $context]);
    $response200->response = 200;
    $response200->description = 'OK';

    $post = new OA\Post(['_context' => $context]);
    $post->operationId = 'newOrderPost';
    $post->responses = [$response200];
    $post->parameters = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $post->requestBody = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $post->security = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $post->deprecated = false;

    $get = new OA\Get(['_context' => $context]);
    $get->operationId = 'newOrderGet';
    $get->responses = [$response200];
    $get->parameters = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $get->requestBody = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $get->security = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $get->deprecated = false;

    $pathItem = new OA\PathItem(['_context' => $context]);
    $pathItem->path = 'newOrder';
    $pathItem->post = $post;
    $pathItem->get = $get;
    $pathItem->put = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->patch = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->delete = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->options = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->head = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->trace = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $pathItem->description = 'Fired for new orders.';

    $spec->webhooks = ['newOrder' => $pathItem]; // @phpstan-ignore assign.propertyType

    $builder = new SpecTreeBuilder();
    $api = $builder->build($spec, []);

    // Both methods must produce separate WebhookNodes
    expect($api->webhooks)->toHaveCount(2);

    $methods = array_map(
        static fn(Radiergummi\OpenApi\Core\Lint\Tree\WebhookNode $w): string => $w->operation->method,
        $api->webhooks,
    );
    expect($methods)->toContain('GET')->toContain('POST');

    // All nodes carry the same description and name
    foreach ($api->webhooks as $webhook) {
        expect($webhook->name)->toBe('newOrder')
            ->and($webhook->description)->toBe('Fired for new orders.')
            ->and($webhook->operation->webhook)->toBeTrue();
    }
});

// ---------------------------------------------------------------------------
// Bug 3: FieldNode::pointer() must include the 'properties/' segment
// ---------------------------------------------------------------------------

it('FieldNode pointer includes the properties/ segment (Bug 3)', function (): void {
    $field = new FieldNode(
        name: 'email',
        type: 'string',
        required: true,
        nullable: false,
        description: null,
        format: null,
        example: null,
        enum: null,
        children: [],
        examples: [],
        ref: null,
        raw: null,
    );

    $component = new ComponentSchemaNode(
        name: 'User',
        description: null,
        fields: [$field],
        raw: null,
    );
    $field->linkParent($component);

    $api = new ApiNode(
        operations: [],
        components: [$component],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: new OA\OpenApi(['_context' => new Context()]),
    );
    $component->linkParent($api);

    expect($field->pointer())->toContain('properties/')
        ->and($field->pointer())->toEndWith('/properties/email');
});

it('FieldNode pointer for a nested field includes properties/ at each level (Bug 3)', function (): void {
    $child = new FieldNode(
        name: 'street',
        type: 'string',
        required: true,
        nullable: false,
        description: null,
        format: null,
        example: null,
        enum: null,
        children: [],
        examples: [],
        ref: null,
        raw: null,
    );

    $parent = new FieldNode(
        name: 'address',
        type: 'object',
        required: false,
        nullable: false,
        description: null,
        format: null,
        example: null,
        enum: null,
        children: [$child],
        examples: [],
        ref: null,
        raw: null,
    );

    $component = new ComponentSchemaNode(
        name: 'User',
        description: null,
        fields: [$parent],
        raw: null,
    );
    $parent->linkParent($component);
    $child->linkParent($parent);

    $api = new ApiNode(
        operations: [],
        components: [$component],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: new OA\OpenApi(['_context' => new Context()]),
    );
    $component->linkParent($api);

    expect($child->pointer())
        ->toContain('properties/address')
        ->toContain('properties/street')
        ->toEndWith('/properties/address/properties/street');
});

/**
 * Helper — build an OA\Schema from a simple shape so the allOf tests can stay terse.
 *
 * @param list<OA\Property|OA\Schema> $properties
 * @param list<string>                $required
 * @param list<OA\Schema>             $allOf
 */
function makeOAComponentSchema(
    string $name,
    array $properties = [],
    array $required = [],
    array $allOf = [],
): OA\Schema {
    $context = new Context();

    $schema = new OA\Schema(['_context' => $context]);
    $schema->schema = $name;
    $schema->description = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $schema->properties = $properties !== [] ? $properties : Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $schema->required = $required !== [] ? $required : Generator::UNDEFINED; // @phpstan-ignore assign.propertyType
    $schema->allOf = $allOf !== [] ? $allOf : Generator::UNDEFINED; // @phpstan-ignore assign.propertyType

    return $schema;
}

function makeOAProperty(string $name, string $type): OA\Property
{
    $context = new Context();

    $property = new OA\Property(['_context' => $context]);
    $property->property = $name;
    $property->type = $type;
    $property->nullable = false;

    return $property;
}

function makeOARef(string $componentName): OA\Schema
{
    $branch = new OA\Schema(['_context' => new Context()]);
    $branch->ref = '#/components/schemas/' . $componentName;

    return $branch;
}

function buildSpecWithComponents(OA\Schema ...$schemas): OA\OpenApi
{
    $spec = makeMinimalSpec();
    $components = new OA\Components(['_context' => new Context()]);
    $components->schemas = $schemas;
    $spec->components = $components;

    return $spec;
}

it('merges allOf-inherited properties via a $ref branch into FieldNodes', function (): void {
    $base = makeOAComponentSchema(
        name: 'Base',
        properties: [makeOAProperty('id', 'integer')],
        required: ['id'],
    );
    $user = makeOAComponentSchema(
        name: 'User',
        properties: [makeOAProperty('name', 'string')],
        required: ['name'],
        allOf: [makeOARef('Base'), makeOAComponentSchema(name: '_inline')],
    );

    $api = (new SpecTreeBuilder())->build(buildSpecWithComponents($base, $user), []);
    $userNode = $api->components[1];

    $names = array_map(static fn(FieldNode $f): string => $f->name, $userNode->fields);

    expect($names)->toBe(['id', 'name'])
        ->and($userNode->fields[0]->required)->toBeTrue()
        ->and($userNode->fields[1]->required)->toBeTrue();
});

it('merges allOf-inherited properties from an inline branch', function (): void {
    $extra = makeOAComponentSchema(
        name: 'WithInline',
        properties: [makeOAProperty('local', 'string')],
        required: [],
        allOf: [
            makeOAComponentSchema(
                name: '_inlineExtra',
                properties: [makeOAProperty('inherited', 'integer')],
                required: ['inherited'],
            ),
        ],
    );

    $api = (new SpecTreeBuilder())->build(buildSpecWithComponents($extra), []);
    $node = $api->components[0];

    $names = array_map(static fn(FieldNode $f): string => $f->name, $node->fields);

    expect($names)->toBe(['inherited', 'local'])
        ->and($node->fields[0]->required)->toBeTrue()
        ->and($node->fields[1]->required)->toBeFalse();
});

it('breaks cycles in allOf $ref chains without infinite recursion', function (): void {
    $a = makeOAComponentSchema(
        name: 'A',
        properties: [makeOAProperty('aProp', 'string')],
        allOf: [makeOARef('B')],
    );
    $b = makeOAComponentSchema(
        name: 'B',
        properties: [makeOAProperty('bProp', 'string')],
        allOf: [makeOARef('A')],
    );

    $api = (new SpecTreeBuilder())->build(buildSpecWithComponents($a, $b), []);
    $aNode = $api->components[0];

    $names = array_map(static fn(FieldNode $f): string => $f->name, $aNode->fields);

    // Both properties land on A — the local one and B's local one inherited via allOf.
    // The chain stops before re-entering A through B's allOf back-reference.
    expect($names)->toContain('aProp')
        ->and($names)->toContain('bProp');
});
