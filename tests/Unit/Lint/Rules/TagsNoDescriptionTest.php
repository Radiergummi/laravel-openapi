<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\TagsNoDescription;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new TagsNoDescription();

    expect($rule->id())->toBe('tags.no-description')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when a tag has a missing or blank description', function (array $tagDescriptions): void {
    $context = OperationNodeFactory::emptyContext(
        declaredTags: ['Users'],
        tagDescriptions: $tagDescriptions,
    );

    $findings = iterator_to_array(new TagsNoDescription()->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('tags.no-description')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('"Users"');
})->with([
    'no description'         => [[]],
    'empty description'      => [['Users' => '']],
    'whitespace description' => [['Users' => '   ']],
]);

it('emits no findings when all tags have descriptions', function (): void {
    $context = OperationNodeFactory::emptyContext(
        declaredTags: ['Users', 'Admin'],
        tagDescriptions: [
            'Users' => 'User management endpoints',
            'Admin' => 'Admin-only endpoints',
        ],
    );

    $findings = iterator_to_array(new TagsNoDescription()->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});

it('emits findings only for tags without descriptions in a mixed set', function (): void {
    $context = OperationNodeFactory::emptyContext(
        declaredTags: ['Users', 'Admin'],
        tagDescriptions: ['Users' => 'Has description'],
    );

    $findings = iterator_to_array(new TagsNoDescription()->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('"Admin"');
});

it('emits no findings when there are no tags', function (): void {
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(new TagsNoDescription()->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});

it('uses the json pointer with the array index in the finding location', function (array $declaredTags, array $tagDescriptions, string $pointer): void {
    $context = OperationNodeFactory::emptyContext(
        declaredTags: $declaredTags,
        tagDescriptions: $tagDescriptions,
    );

    $findings = iterator_to_array(new TagsNoDescription()->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->location->jsonPointer)->toBe($pointer);
})->with([
    'single undescribed tag at index 0' => [['Search'], [], '#/tags/0'],
    'second tag undescribed at index 1' => [['Users', 'Admin'], ['Users' => 'User management'], '#/tags/1'],
]);
