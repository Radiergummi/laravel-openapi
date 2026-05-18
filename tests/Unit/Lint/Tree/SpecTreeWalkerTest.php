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
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ApiRule as ApiRuleInterface;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ComponentSchemaRule as ComponentSchemaRuleInterface;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\FieldRule as FieldRuleInterface;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\Finalizable;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleInterface;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ResponseRule as ResponseRuleInterface;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Core\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\Tree\SpecTreeWalker;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint', 'tree');

function makeTestApi(): ApiNode
{
    $field = new FieldNode(
        name: 'email',
        type: 'string',
        required: true,
        nullable: false,
        description: 'Email address',
        format: 'email',
        example: 'test@example.com',
        enum: null,
        children: [],
        examples: [],
        ref: null,
        raw: null,
    );

    $response = new ResponseNode(
        statusCode: 200,
        description: 'Success',
        fields: [$field],
        examples: [],
        schemaRef: null,
        headers: [],
        links: [],
        raw: null,
    );
    $field->linkParent($response);

    $operation = new OperationNode(
        pathUri: '/api/v0/users',
        method: 'GET',
        operationId: 'getUsers',
        summary: 'Get users',
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [$response],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
        webhook: false,
    );
    $response->linkParent($operation);

    $componentField = new FieldNode(
        name: 'id',
        type: 'integer',
        required: true,
        nullable: false,
        description: 'ID',
        format: null,
        example: 1,
        enum: null,
        children: [],
        examples: [],
        ref: null,
        raw: null,
    );

    $component = new ComponentSchemaNode(
        name: 'User',
        description: 'A user',
        fields: [$componentField],
        raw: null,
    );
    $componentField->linkParent($component);

    $api = new ApiNode(
        operations: [$operation],
        components: [$component],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: new OA\OpenApi(['_context' => new Context()]),
    );
    $operation->linkParent($api);
    $component->linkParent($api);

    return $api;
}

function makeTestContext(ApiNode $api): LintContext
{
    return new LintContext(
        api: $api,
        index: TreeIndex::empty(),
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: [],
    );
}

it('dispatches ApiRule once for the root node', function (): void {
    $api = makeTestApi();
    $context = makeTestContext($api);
    $dispatched = [];

    $rule = new class ($dispatched) implements Rule, ApiRuleInterface {
        public function __construct(private array &$dispatched) {} // @phpstan-ignore property.onlyWritten
        public function id(): string
        {
            return 'test.api';
        }
        public function level(): int
        {
            return 0;
        }
        public function description(): string
        {
            return 'test rule';
        }
        public function checkApi(ApiNode $api, LintContext $context): iterable
        {
            $this->dispatched[] = 'api';

            return [];
        }
    };

    $walker = new SpecTreeWalker([$rule]);
    iterator_to_array($walker->walk($api, $context));

    expect($dispatched)->toBe(['api']);
});

it('dispatches OperationRule for each operation', function (): void {
    $api = makeTestApi();
    $context = makeTestContext($api);
    $dispatched = [];

    $rule = new class ($dispatched) implements Rule, OperationRuleInterface {
        public function __construct(private array &$dispatched) {} // @phpstan-ignore property.onlyWritten
        public function id(): string
        {
            return 'test.op';
        }
        public function level(): int
        {
            return 0;
        }
        public function description(): string
        {
            return 'test rule';
        }
        public function checkOperation(
            OperationNode $operation,
            LintContext $context,
        ): iterable {
            $this->dispatched[] = $operation->operationId;

            return [];
        }
    };

    $walker = new SpecTreeWalker([$rule]);
    iterator_to_array($walker->walk($api, $context));

    expect($dispatched)->toBe(['getUsers']);
});

it(
    'dispatches FieldRule for all fields including component fields',
    function (): void {
        $api = makeTestApi();
        $context = makeTestContext($api);
        $dispatched = [];

        $rule = new class ($dispatched) implements Rule, FieldRuleInterface {
            public function __construct(private array &$dispatched) {} // @phpstan-ignore property.onlyWritten
            public function id(): string
            {
                return 'test.field';
            }
            public function level(): int
            {
                return 0;
            }
            public function description(): string
            {
                return 'test rule';
            }
            public function checkField(
                FieldNode $field,
                LintContext $context,
            ): iterable {
                $this->dispatched[] = $field->name;

                return [];
            }
        };

        $walker = new SpecTreeWalker([$rule]);
        iterator_to_array($walker->walk($api, $context));

        // "email" from response fields + "id" from component fields
        expect($dispatched)->toBe(['email', 'id']);
    },
);

it('dispatches ResponseRule for each response', function (): void {
    $api = makeTestApi();
    $context = makeTestContext($api);
    $dispatched = [];

    $rule = new class ($dispatched) implements Rule, ResponseRuleInterface {
        public function __construct(private array &$dispatched) {} // @phpstan-ignore property.onlyWritten
        public function id(): string
        {
            return 'test.resp';
        }
        public function level(): int
        {
            return 0;
        }
        public function description(): string
        {
            return 'test rule';
        }
        public function checkResponse(
            ResponseNode $response,
            LintContext $context,
        ): iterable {
            $this->dispatched[] = $response->statusCode;

            return [];
        }
    };

    $walker = new SpecTreeWalker([$rule]);
    iterator_to_array($walker->walk($api, $context));

    expect($dispatched)->toBe([200]);
});

it('dispatches ComponentSchemaRule for each component', function (): void {
    $api = makeTestApi();
    $context = makeTestContext($api);
    $dispatched = [];

    $rule = new class ($dispatched) implements
        Rule,
        ComponentSchemaRuleInterface {
        public function __construct(private array &$dispatched) {} // @phpstan-ignore property.onlyWritten
        public function id(): string
        {
            return 'test.comp';
        }
        public function level(): int
        {
            return 0;
        }
        public function description(): string
        {
            return 'test rule';
        }
        public function checkComponentSchema(
            ComponentSchemaNode $comp,
            LintContext $context,
        ): iterable {
            $this->dispatched[] = $comp->name;

            return [];
        }
    };

    $walker = new SpecTreeWalker([$rule]);
    iterator_to_array($walker->walk($api, $context));

    expect($dispatched)->toBe(['User']);
});

it('calls Finalizable after all traversal', function (): void {
    $api = makeTestApi();
    $context = makeTestContext($api);
    $dispatched = [];

    $rule = new class ($dispatched) implements
        Rule,
        OperationRuleInterface,
        Finalizable {
        public function __construct(private array &$dispatched) {} // @phpstan-ignore property.onlyWritten
        public function id(): string
        {
            return 'test.final';
        }
        public function level(): int
        {
            return 0;
        }
        public function description(): string
        {
            return 'test rule';
        }
        public function checkOperation(
            OperationNode $operation,
            LintContext $context,
        ): iterable {
            $this->dispatched[] = 'operation';

            return [];
        }
        public function finalize(LintContext $context): iterable
        {
            $this->dispatched[] = 'finalize';

            return [];
        }
    };

    $walker = new SpecTreeWalker([$rule]);
    iterator_to_array($walker->walk($api, $context));

    expect($dispatched)->toBe(['operation', 'finalize']);
});

it('collects findings from multiple rules', function (): void {
    $api = makeTestApi();
    $context = makeTestContext($api);

    $rule = new class () implements Rule, OperationRuleInterface {
        public function id(): string
        {
            return 'test.finding';
        }
        public function level(): int
        {
            return 0;
        }
        public function description(): string
        {
            return 'test rule';
        }
        public function checkOperation(
            OperationNode $operation,
            LintContext $context,
        ): iterable {
            yield new Finding(
                ruleId: 'test.finding',
                level: 0,
                message: 'Test finding for ' . $operation->operationId,
                location: new FindingLocation(),
            );
        }
    };

    $walker = new SpecTreeWalker([$rule]);
    $findings = iterator_to_array($walker->walk($api, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->message)
        ->toBe('Test finding for getUsers');
});

it(
    'skips field traversal when no FieldRule or ExampleRule is registered',
    function (): void {
        $api = makeTestApi();
        $context = makeTestContext($api);
        $dispatched = [];

        // Only register an OperationRule — no FieldRule
        $rule = new class ($dispatched) implements Rule, OperationRuleInterface {
            public function __construct(private array &$dispatched) {} // @phpstan-ignore property.onlyWritten
            public function id(): string
            {
                return 'test.op';
            }
            public function level(): int
            {
                return 0;
            }
            public function description(): string
            {
                return 'test rule';
            }
            public function checkOperation(
                OperationNode $operation,
                LintContext $context,
            ): iterable {
                $this->dispatched[] = 'op:' . $operation->operationId;

                return [];
            }
        };

        $walker = new SpecTreeWalker([$rule]);
        iterator_to_array($walker->walk($api, $context));

        // Only operation dispatches — no field dispatches
        expect($dispatched)->toBe(['op:getUsers']);
    },
);

it(
    'deduplicates Finalizable calls for rules implementing multiple interfaces',
    function (): void {
        $api = makeTestApi();
        $context = makeTestContext($api);
        $finalizeCount = 0;

        $rule = new class ($finalizeCount) implements
            Rule,
            OperationRuleInterface,
            ResponseRuleInterface,
            Finalizable {
            public function __construct(private int &$finalizeCount) {} // @phpstan-ignore property.onlyWritten
            public function id(): string
            {
                return 'test.multi';
            }
            public function level(): int
            {
                return 0;
            }
            public function description(): string
            {
                return 'test rule';
            }
            public function checkOperation(
                OperationNode $op,
                LintContext $ctx,
            ): iterable {
                return [];
            }
            public function checkResponse(
                ResponseNode $r,
                LintContext $ctx,
            ): iterable {
                return [];
            }
            public function finalize(LintContext $context): iterable
            {
                $this->finalizeCount++;

                return [];
            }
        };

        $walker = new SpecTreeWalker([$rule]);
        iterator_to_array($walker->walk($api, $context));

        expect($finalizeCount)->toBe(1);
    },
);

it('enriches findings from operation rules with operation location defaults', function (): void {
    $api = makeTestApi();
    $context = makeTestContext($api);

    // Rule that yields a finding with only a jsonPointer
    $rule = new class () implements Rule, OperationRuleInterface {
        public function id(): string
        {
            return 'test.enrich';
        }
        public function level(): int
        {
            return 0;
        }
        public function description(): string
        {
            return 'test rule';
        }
        public function checkOperation(
            OperationNode $operation,
            LintContext $context,
        ): iterable {
            yield new Finding(
                ruleId: 'test.enrich',
                level: 0,
                message: 'Minimal location',
                location: new FindingLocation(jsonPointer: '/paths/test'),
            );
        }
    };

    $walker = new SpecTreeWalker([$rule]);
    $findings = iterator_to_array($walker->walk($api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->location->jsonPointer)->toBe('/paths/test')
        ->and($findings[0]->location->routeMethod)->toBe('GET')
        ->and($findings[0]->location->routeUri)->toBe('/api/v0/users');
});

it('enriches findings from field rules with operation location defaults', function (): void {
    $api = makeTestApi();
    $context = makeTestContext($api);

    // Rule that yields a finding with only a jsonPointer
    $rule = new class () implements Rule, FieldRuleInterface {
        public function id(): string
        {
            return 'test.field-enrich';
        }
        public function level(): int
        {
            return 0;
        }
        public function description(): string
        {
            return 'test rule';
        }
        public function checkField(
            FieldNode $field,
            LintContext $context,
        ): iterable {
            yield new Finding(
                ruleId: 'test.field-enrich',
                level: 0,
                message: 'Field finding for ' . $field->name,
                location: new FindingLocation(jsonPointer: $field->pointer()),
            );
        }
    };

    $walker = new SpecTreeWalker([$rule]);
    $findings = iterator_to_array($walker->walk($api, $context), false);

    // "email" field is inside operation, "id" field is in component (no operation context)
    expect($findings)->toHaveCount(2);

    // Finding for "email" (inside operation) should be enriched
    $emailFinding = collect($findings)->first(fn(Finding $f) => str_contains($f->message, 'email'));
    expect($emailFinding->location->routeMethod)->toBe('GET')
        ->and($emailFinding->location->routeUri)->toBe('/api/v0/users');

    // Finding for "id" (inside component, no operation) should NOT be enriched
    $idFinding = collect($findings)->first(fn(Finding $f) => str_contains($f->message, 'id'));
    expect($idFinding->location->routeMethod)->toBeNull()
        ->and($idFinding->location->routeUri)->toBeNull();
});

it('does not overwrite explicitly set location fields', function (): void {
    $api = makeTestApi();
    $context = makeTestContext($api);

    // Rule that yields a finding with some explicit location fields
    $rule = new class () implements Rule, OperationRuleInterface {
        public function id(): string
        {
            return 'test.no-overwrite';
        }
        public function level(): int
        {
            return 0;
        }
        public function description(): string
        {
            return 'test rule';
        }
        public function checkOperation(
            OperationNode $operation,
            LintContext $context,
        ): iterable {
            yield new Finding(
                ruleId: 'test.no-overwrite',
                level: 0,
                message: 'Explicit fields',
                location: new FindingLocation(
                    routeMethod: 'POST',
                    routeUri: '/custom',
                    jsonPointer: '/test',
                ),
            );
        }
    };

    $walker = new SpecTreeWalker([$rule]);
    $findings = iterator_to_array($walker->walk($api, $context));

    // Explicit values should NOT be overwritten by defaults
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->location->routeMethod)->toBe('POST')
        ->and($findings[0]->location->routeUri)->toBe('/custom')
        ->and($findings[0]->location->jsonPointer)->toBe('/test');
});

it('uses the parent field pointer as default for nested field findings', function (): void {
    // Build a parent field with a nested child field
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
        required: true,
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

    $response = new ResponseNode(
        statusCode: 200,
        description: 'OK',
        fields: [$parent],
        examples: [],
        schemaRef: null,
        headers: [],
        links: [],
        raw: null,
    );
    $parent->linkParent($response);
    $child->linkParent($parent);

    $operation = new OperationNode(
        pathUri: '/api/v0/test',
        method: 'GET',
        operationId: 'test',
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [$response],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
        webhook: false,
    );
    $response->linkParent($operation);

    $api = new ApiNode(
        operations: [$operation],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: new OA\OpenApi(['_context' => new Context()]),
    );
    $operation->linkParent($api);

    $context = makeTestContext($api);

    // Rule that yields a bare finding (no explicit location) per field
    $rule = new class () implements Rule, FieldRuleInterface {
        public function id(): string
        {
            return 'test.nested-ptr';
        }
        public function level(): int
        {
            return 0;
        }
        public function description(): string
        {
            return 'test rule';
        }
        public function checkField(FieldNode $field, LintContext $context): iterable
        {
            yield new Finding(
                ruleId: 'test.nested-ptr',
                level: 0,
                message: 'field:' . $field->name,
                location: new FindingLocation(),
            );
        }
    };

    $walker = new SpecTreeWalker([$rule]);
    $findings = iterator_to_array($walker->walk($api, $context), false);

    expect($findings)->toHaveCount(2);

    $parentFinding = null;
    $childFinding  = null;

    foreach ($findings as $f) {
        if (str_contains($f->message, 'address')) {
            $parentFinding = $f;
        } elseif (str_contains($f->message, 'street')) {
            $childFinding = $f;
        }
    }

    expect($parentFinding)->toBeInstanceOf(Finding::class);
    expect($childFinding)->toBeInstanceOf(Finding::class);

    assert($parentFinding instanceof Finding);
    assert($childFinding instanceof Finding);

    // Parent field pointer is set as default on the parent finding.
    expect($parentFinding->location->jsonPointer)->toContain('address');

    // Child finding's pointer is its own (e.g. "properties/address/properties/street"),
    // which includes the parent segment but resolves to the child's own location.
    // Before the fix, the parent's pointer ('properties/address') overwrote the child's.
    expect($childFinding->location->jsonPointer)->toContain('street');
});

it('does not set routeUri or routeName for webhook operation findings', function (): void {
    $webhookOperation = new OperationNode(
        pathUri: 'newOrder',
        method: 'POST',
        operationId: 'newOrderPost',
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Post(['_context' => new Context()]),
        webhook: true,
    );

    $spec = new OA\OpenApi(['_context' => new Context()]);
    $api = new ApiNode(
        operations: [],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );

    $location = FindingLocation::fromOperation($webhookOperation);

    // routeUri must be null (webhook name must NOT leak into route fields)
    expect($location->routeUri)->toBeNull()
        ->and($location->routeName)->toBeNull()
        // routeMethod is still set so formatters can show GET/POST etc.
        ->and($location->routeMethod)->toBe('POST');
});
