<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\VisibilityAttributeNoOp;
use Radiergummi\OpenApi\Support\Visibility\VisibilityMode;
use Radiergummi\OpenApi\Support\Visibility\VisibilityResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\VisibilityNoOpController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function visibilityNoOpFindings(VisibilityMode $mode, string $method): array
{
    return iterator_to_array(
        new VisibilityAttributeNoOp(new VisibilityResolver($mode))->checkRoute(
            ActionDescriptorFactory::forControllerMethod(VisibilityNoOpController::class, $method),
            OperationNodeFactory::emptyContext(),
        ),
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new VisibilityAttributeNoOp(new VisibilityResolver(VisibilityMode::Public));

    expect($rule->id())
        ->toBe('visibility.attribute-no-op')
        ->and($rule->level())->toBe(2);
});

it('flags an unconditional #[Expose] under public-default visibility', function (): void {
    expect(visibilityNoOpFindings(VisibilityMode::Public, 'exposeInPublic'))
        ->toEmitFinding(ruleId: 'visibility.attribute-no-op', messageContains: 'public-default');
});

it('flags an unconditional #[Hide] under hidden-default visibility', function (): void {
    expect(visibilityNoOpFindings(VisibilityMode::Hidden, 'hideInHidden'))
        ->toEmitFinding(ruleId: 'visibility.attribute-no-op', messageContains: 'hidden-default');
});

it('does not flag an env-scoped #[Expose] under public-default visibility', function (): void {
    expect(visibilityNoOpFindings(VisibilityMode::Public, 'envScopedExposeInPublic'))->toBe([]);
});

it('does not flag an unconditional #[Hide] under public-default visibility', function (): void {
    expect(visibilityNoOpFindings(VisibilityMode::Public, 'hideInHidden'))->toBe([]);
});
