<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeBuilder;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeWalker;
use Radiergummi\OpenApi\Lint\TreeIndex;
use Radiergummi\OpenApi\Lint\Visitors\FieldRule as FieldRuleVisitor;

uses()->group('openapi', 'lint');

it('stamps CONTEXT_SOURCE_CLASS on findings emitted under a component schema whose source class is known', function (): void {
    $rule = new class () implements Rule, FieldRuleVisitor {
        public function id(): string
        {
            return 'test.field-rule';
        }
        public function level(): int
        {
            return 3;
        }
        public function description(): string
        {
            return 'test';
        }
        public function checkField(FieldNode $field, LintContext $context): iterable
        {
            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
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
    $index = TreeIndex::build($api, $document, [$rule->id()], []);
    $context = new LintContext(api: $api, index: $index, rawSpec: $document, actionDescriptors: [], suppressions: []);

    $walker = new SpecTreeWalker([$rule]);
    $findings = iterator_to_array($walker->walk($api, $context), preserve_keys: false);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_CLASS] ?? null)->toBe(stdClass::class)
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_MEMBER] ?? null)->toBe('error_uri');
});
