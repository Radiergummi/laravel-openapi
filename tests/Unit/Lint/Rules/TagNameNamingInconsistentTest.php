<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\Rules\TagNameNamingInconsistent;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new TagNameNamingInconsistent();

    expect($rule->id())->toBe('tag.name-naming-inconsistent')
        ->and($rule->level())->toBe(3);
});

it('default (pascal): passes valid PascalCase tag names', function (array $tags): void {
    $rule = new TagNameNamingInconsistent();
    $context = OperationNodeFactory::emptyContext(declaredTags: $tags);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toBe([]);
})->with([
    'single tag'    => [['Projects']],
    'multiple tags' => [['Projects', 'ImportJobs', 'Users']],
    'no tags'       => [[]],
]);

it('default (pascal): flags non-PascalCase tag names', function (string $tag): void {
    $rule = new TagNameNamingInconsistent();
    $context = OperationNodeFactory::emptyContext(declaredTags: [$tag]);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('tag.name-naming-inconsistent')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain("\"{$tag}\"")
        ->and($findings[0]->message)->toContain('PascalCase');
})->with([
    'kebab-case' => ['import-jobs'],
    'snake_case' => ['import_jobs'],
]);

it('emits one finding per offending tag', function (): void {
    $rule = new TagNameNamingInconsistent();
    $context = OperationNodeFactory::emptyContext(declaredTags: ['Projects', 'import-jobs', 'user_management']);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toHaveCount(2);
});

it('records the json pointer with the offending tag index', function (array $tags, int $expectedIndex): void {
    $rule = new TagNameNamingInconsistent();
    $context = OperationNodeFactory::emptyContext(declaredTags: $tags);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->location->jsonPointer)->toBe("#/tags/{$expectedIndex}");
})->with([
    'first tag offending'  => [['bad-tag'], 0],
    'second tag offending' => [['Projects', 'bad-tag'], 1],
]);

it('provides a fix hint with the case label and example', function (): void {
    $rule = new TagNameNamingInconsistent();
    $context = OperationNodeFactory::emptyContext(declaredTags: ['bad-tag']);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings[0]->fixHint)
        ->toContain('PascalCase')
        ->toContain(IdentifierCase::Pascal->example());
});

it('kebab case: passes a valid kebab-case tag name', function (): void {
    $rule = new TagNameNamingInconsistent(IdentifierCase::Kebab);
    $context = OperationNodeFactory::emptyContext(declaredTags: ['import-jobs']);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});

it('kebab case: flags a PascalCase tag name', function (): void {
    $rule = new TagNameNamingInconsistent(IdentifierCase::Kebab);
    $context = OperationNodeFactory::emptyContext(declaredTags: ['ImportJobs']);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('kebab-case');
});
