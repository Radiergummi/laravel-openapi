<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeBuilder;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeWalker;
use Radiergummi\OpenApi\Lint\TreeIndex;
use Radiergummi\OpenApi\Lint\Visitors\FieldRule as FieldRuleVisitor;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Lint\Visitors\ResponseRule as ResponseRuleVisitor;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\CleanController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ResponseEmptyController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('stamps CONTEXT_SOURCE_CLASS on findings emitted under a component schema whose source class is known', function (): void {
    $rule = new class () implements Rule, FieldRuleVisitor {
        public string $id = 'test.field-rule';
        public Severity $severity = Severity::Inconsistent;
        public string $description = 'test';
        public function checkField(FieldNode $field, LintContext $context): iterable
        {
            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: 'fire on ' . $field->name,
            );
        }
    };

    $schema = new OA\Schema(['schema' => 'Some', 'properties' => [
        new OA\Property(['property' => 'error_uri', 'type' => 'string']),
    ]]);
    $document = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 't', 'version' => '1']),
        'paths' => [],
        'components' => new OA\Components(['schemas' => [$schema]]),
    ]);

    $builder = new SpecTreeBuilder(componentClassMap: ['Some' => stdClass::class]);
    $api = $builder->build($document, []);
    $index = TreeIndex::build($api, $document, [$rule->id], []);
    $context = new LintContext(api: $api, index: $index, rawSpec: $document, actionDescriptors: [], suppressions: []);

    $walker = new SpecTreeWalker([$rule]);
    $findings = iterator_to_array($walker->walk($api, $context), preserve_keys: false);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_CLASS] ?? null)->toBe(stdClass::class)
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_MEMBER] ?? null)->toBe('error_uri');
});

it('stamps CONTEXT_SOURCE_CLASS from the controller on operation-level findings', function (): void {
    $rule = new class () implements Rule, OperationRuleVisitor {
        public string $id = 'test.operation-rule';
        public Severity $severity = Severity::Degraded;
        public string $description = 'test';
        public function checkOperation(OperationNode $operation, LintContext $context): iterable
        {
            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: 'fire',
            );
        }
    };

    $descriptor = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list');
    $operation = OperationNodeFactory::makeOperation(descriptor: $descriptor, operationId: null);
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);
    $api = new ApiNode(
        operations: [$operation],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );
    $index = TreeIndex::empty();
    $context = new LintContext(api: $api, index: $index, rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $walker = new SpecTreeWalker([$rule]);
    $findings = iterator_to_array($walker->walk($api, $context), preserve_keys: false);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_CLASS] ?? null)->toBe(CleanController::class);
});

it('does not stamp CONTEXT_SOURCE_CLASS on operation-level findings when no controller is known', function (): void {
    $rule = new class () implements Rule, OperationRuleVisitor {
        public string $id = 'test.operation-rule';
        public Severity $severity = Severity::Degraded;
        public string $description = 'test';
        public function checkOperation(OperationNode $operation, LintContext $context): iterable
        {
            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: 'fire',
            );
        }
    };

    // No descriptor → no controller → no stamp
    $operation = OperationNodeFactory::makeOperation(descriptor: null, operationId: null);
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);
    $api = new ApiNode(
        operations: [$operation],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );
    $index = TreeIndex::empty();
    $context = new LintContext(api: $api, index: $index, rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $walker = new SpecTreeWalker([$rule]);
    $findings = iterator_to_array($walker->walk($api, $context), preserve_keys: false);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->context)->not->toHaveKey(Finding::CONTEXT_SOURCE_CLASS);
});

it('stamps the controller CONTEXT_SOURCE_CLASS on response-level findings', function (): void {
    $rule = new class () implements Rule, ResponseRuleVisitor {
        public string $id = 'test.response-rule';
        public Severity $severity = Severity::Degraded;
        public string $description = 'test';
        public function checkResponse(ResponseNode $response, LintContext $context): iterable
        {
            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: 'fire on ' . $response->statusCode,
            );
        }
    };

    $descriptor = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list');
    $operation = OperationNodeFactory::makeOperation(descriptor: $descriptor, operationId: null);
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);
    $api = new ApiNode(
        operations: [$operation],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );
    $index = TreeIndex::empty();
    $context = new LintContext(api: $api, index: $index, rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $walker = new SpecTreeWalker([$rule]);
    $findings = iterator_to_array($walker->walk($api, $context), preserve_keys: false);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_CLASS] ?? null)->toBe(CleanController::class);
});

it('stamps each operation its own controller for response-level findings (no cross-controller bleed)', function (): void {
    $rule = new class () implements Rule, ResponseRuleVisitor {
        public string $id = 'test.response-rule';
        public Severity $severity = Severity::Degraded;
        public string $description = 'test';
        public function checkResponse(ResponseNode $response, LintContext $context): iterable
        {
            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: 'fire',
            );
        }
    };

    $descriptorA = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list', '/a');
    $descriptorB = ActionDescriptorFactory::forControllerMethod(ResponseEmptyController::class, 'index', '/b');
    $operationA = OperationNodeFactory::makeOperation(pathUri: '/a', descriptor: $descriptorA, operationId: null);
    $operationB = OperationNodeFactory::makeOperation(pathUri: '/b', descriptor: $descriptorB, operationId: null);

    $spec = new OA\OpenApi(['openapi' => '3.1.0']);
    $api = new ApiNode(
        operations: [$operationA, $operationB],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );
    $index = TreeIndex::empty();
    $context = new LintContext(api: $api, index: $index, rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $walker = new SpecTreeWalker([$rule]);
    $findings = iterator_to_array($walker->walk($api, $context), preserve_keys: false);

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_CLASS] ?? null)->toBe(CleanController::class)
        ->and($findings[1]->context[Finding::CONTEXT_SOURCE_CLASS] ?? null)->toBe(ResponseEmptyController::class);
});

it('stamps different CONTEXT_SOURCE_CLASS values for operations from different controllers', function (): void {
    $rule = new class () implements Rule, OperationRuleVisitor {
        public string $id = 'test.operation-rule';
        public Severity $severity = Severity::Degraded;
        public string $description = 'test';
        public function checkOperation(OperationNode $operation, LintContext $context): iterable
        {
            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: 'fire on ' . $operation->pathUri,
            );
        }
    };

    $descriptorA = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list', '/a');
    $descriptorB = ActionDescriptorFactory::forControllerMethod(ResponseEmptyController::class, 'index', '/b');
    $operationA = OperationNodeFactory::makeOperation(pathUri: '/a', descriptor: $descriptorA, operationId: null);
    $operationB = OperationNodeFactory::makeOperation(pathUri: '/b', descriptor: $descriptorB, operationId: null);

    $spec = new OA\OpenApi(['openapi' => '3.1.0']);
    $api = new ApiNode(
        operations: [$operationA, $operationB],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );
    $index = TreeIndex::empty();
    $context = new LintContext(api: $api, index: $index, rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $walker = new SpecTreeWalker([$rule]);
    $findings = iterator_to_array($walker->walk($api, $context), preserve_keys: false);

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_CLASS] ?? null)->toBe(CleanController::class)
        ->and($findings[1]->context[Finding::CONTEXT_SOURCE_CLASS] ?? null)->toBe(ResponseEmptyController::class);
});
