<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\ErrorsResolverFailed;
use Radiergummi\OpenApi\Support\Generator\Stages\ErrorResponseInferenceStage;

uses()->group('openapi', 'lint');

// `errors.resolver-failed` is a registration stub — it has no visitor. The finding is emitted
// by ErrorResponseInferenceStage (covered by ErrorResponseInferenceStageTest); these tests pin
// the stub's registration contract so its identity stays stable.

it('exposes the stable rule id', function (): void {
    expect(new ErrorsResolverFailed()->id())->toBe('errors.resolver-failed');
});

it('reports a warning-level severity', function (): void {
    expect(new ErrorsResolverFailed()->level())->toBe(2);
});

it('provides a non-empty description', function (): void {
    expect(new ErrorsResolverFailed()->description())->not->toBe('');
});

it('exposes an actionable fix hint pointing at the resolver', function (): void {
    // The hint is aliased from the stage; assert on its content so an accidental gutting of the
    // message is caught (a plain `toBe` against the alias can never fail — PHP constants can't drift).
    expect(ErrorsResolverFailed::FIX_HINT)
        ->toBe(ErrorResponseInferenceStage::RESOLVER_FAILED_FIX_HINT)
        ->toContain('ErrorResponseResolver');
});
