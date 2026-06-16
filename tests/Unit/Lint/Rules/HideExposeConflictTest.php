<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\HideExposeConflict;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\HideExposeConflictController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function hideExposeConflictFindings(string $method): array
{
    return iterator_to_array(
        new HideExposeConflict()->checkRoute(
            ActionDescriptorFactory::forControllerMethod(HideExposeConflictController::class, $method),
            OperationNodeFactory::emptyContext(),
        ),
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new HideExposeConflict();

    expect($rule->id())
        ->toBe('visibility.hide-expose-conflict')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when #[Hide] and #[Expose] both apply unconditionally', function (): void {
    expect(hideExposeConflictFindings('both'))
        ->toEmitFinding(ruleId: 'visibility.hide-expose-conflict', messageContains: 'both #[Hide] and #[Expose]');
});

it('emits a finding when env-scoped #[Hide]/#[Expose] overlap in the active environment', function (): void {
    app()->detectEnvironment(fn(): string => 'production');

    expect(hideExposeConflictFindings('envOverlap'))
        ->toEmitFinding(ruleId: 'visibility.hide-expose-conflict', messageContains: 'production');
});

it('emits no finding when env-scoped attributes do not apply to the active environment', function (): void {
    // envOverlap scopes both attributes to `production`; under the default `testing` env neither matches.
    expect(hideExposeConflictFindings('envOverlap'))->toBe([]);
});

it('emits no finding when #[Hide] and #[Expose] env scopes are disjoint', function (): void {
    expect(hideExposeConflictFindings('envDisjoint'))->toBe([]);
});
