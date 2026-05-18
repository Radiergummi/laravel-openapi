<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Lint\SuppressionCollector;
use Radiergummi\OpenApi\Core\Lint\SuppressionDirective;
use Radiergummi\OpenApi\Core\Lint\SuppressionScope;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Fixtures\Action;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithSuppressedData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithSuppressedDataController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\SuppressedFixtureController;
use Spatie\LaravelData\Data;

uses()->group('openapi', 'lint');

function suppressionDescriptor(string $method): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route('GET', 'test', []),
        controller: new ReflectionClass(SuppressedFixtureController::class),
        method: new ReflectionMethod(SuppressedFixtureController::class, $method),
        summary: null,
        description: null,
    );
}

/**
 * @param list<SuppressionDirective> $directives
 *
 * @return list<SuppressionDirective>
 */
function directivesForRule(array $directives, string $ruleId): array
{
    return array_values(array_filter(
        $directives,
        static fn(SuppressionDirective $d): bool => $d->ruleId === $ruleId,
    ));
}

it('collects a method-scoped directive', function (): void {
    $directives = (new SuppressionCollector([Data::class]))->collect([
        suppressionDescriptor('methodWithSuppression'),
    ]);

    $method = directivesForRule($directives, 'response.empty');

    expect($method)->toHaveCount(1)
        ->and($method[0]->scope)->toBe(SuppressionScope::MethodScope)
        ->and($method[0]->reason)->toBe('method scope')
        ->and($method[0]->targetMember)->toBe('methodWithSuppression')
        ->and($method[0]->methodStartLine)->toBeInt()
        ->and($method[0]->methodEndLine)->toBeInt();
});

it('collects a class-scoped directive from the controller', function (): void {
    $directives = (new SuppressionCollector([Data::class]))->collect([
        suppressionDescriptor('methodWithoutSuppression'),
    ]);

    $class = directivesForRule($directives, 'tag.duplicate');

    expect($class)->toHaveCount(1)
        ->and($class[0]->scope)->toBe(SuppressionScope::ClassScope)
        ->and($class[0]->targetClass)->toBe(SuppressedFixtureController::class)
        ->and($class[0]->targetMember)->toBeNull();
});

it('does not collect a method directive for an un-annotated method', function (): void {
    $directives = (new SuppressionCollector([Data::class]))->collect([
        suppressionDescriptor('methodWithoutSuppression'),
    ]);

    expect(directivesForRule($directives, 'response.empty'))->toBe([]);
});

it('collects class and property directives through a Data parameter', function (): void {
    $directives = (new SuppressionCollector([Data::class]))->collect([
        suppressionDescriptor('methodWithDataParam'),
    ]);

    $property = directivesForRule($directives, 'field.invalid-format');
    $dataClass = directivesForRule($directives, 'field.no-effect');

    expect($property)->toHaveCount(1)
        ->and($property[0]->scope)->toBe(SuppressionScope::PropertyScope)
        ->and($property[0]->targetMember)->toBe('name')
        ->and($property[0]->reason)->toBe('property scope')
        ->and($dataClass)->toHaveCount(1)
        ->and($dataClass[0]->scope)->toBe(SuppressionScope::ClassScope);
});

it('deduplicates directives across descriptors sharing a class and method', function (): void {
    $directives = (new SuppressionCollector([Data::class]))->collect([
        suppressionDescriptor('methodWithSuppression'),
        suppressionDescriptor('methodWithSuppression'),
    ]);

    expect(directivesForRule($directives, 'response.empty'))->toHaveCount(1)
        ->and(directivesForRule($directives, 'tag.duplicate'))->toHaveCount(1);
});

it('collects class and property directives through a Domain Action parameter', function (): void {
    // ActionWithSuppressedData is a Domain Action whose constructor carries
    // SuppressedFixtureData. The collector must follow the indirection and
    // reach the #[IgnoreLint] directives on the Data class and its properties.
    $descriptor = new ActionDescriptor(
        route: new Route('POST', 'test', []),
        controller: new ReflectionClass(ActionWithSuppressedDataController::class),
        method: new ReflectionMethod(ActionWithSuppressedDataController::class, 'create'),
        summary: null,
        description: null,
    );

    $directives = (new SuppressionCollector(
        payloadClasses: [Data::class],
        indirectionClasses: [Action::class],
    ))->collect([$descriptor]);

    $property = directivesForRule($directives, 'field.invalid-format');
    $dataClass = directivesForRule($directives, 'field.no-effect');

    expect($property)->toHaveCount(1)
        ->and($property[0]->scope)->toBe(SuppressionScope::PropertyScope)
        ->and($property[0]->targetMember)->toBe('name')
        ->and($dataClass)->toHaveCount(1)
        ->and($dataClass[0]->scope)->toBe(SuppressionScope::ClassScope);
});
