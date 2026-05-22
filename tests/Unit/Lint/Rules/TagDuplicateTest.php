<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Rules\TagDuplicate;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new TagDuplicate();

    expect($rule->id())->toBe('tag.duplicate')->and($rule->level())->toBe(0);
});

it('emits a finding when an operation has duplicate tags', function (): void {
    $operation = OperationNodeFactory::makeOperation(tags: ['Search', 'Search', 'Users']);

    $findings = iterator_to_array(
        new TagDuplicate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('tag.duplicate')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('"Search"')
        ->and($findings[0]->message)->toContain('2 times');
});

it('emits no findings when all tags are unique', function (): void {
    $operation = OperationNodeFactory::makeOperation(tags: ['Search', 'Users', 'Admin']);

    $findings = iterator_to_array(
        new TagDuplicate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when an operation has no tags', function (): void {
    $operation = OperationNodeFactory::makeOperation(tags: []);

    $findings = iterator_to_array(
        new TagDuplicate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits multiple findings for multiple duplicated tags', function (): void {
    $operation = OperationNodeFactory::makeOperation(tags: ['Search', 'Search', 'Admin', 'Admin']);

    $findings = iterator_to_array(
        new TagDuplicate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(2);
});
