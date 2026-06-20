<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Lint;

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalTransformerClassMissing;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:fractal');

#[TransformerField('id', type: 'integer')]
class ExistingTransformer {}

class TransformerClassMissingController
{
    #[FractalResponse(transformer: 'App\\Transformers\\Nope\\BookTrnasformer')]
    public function typo(): void {}

    #[FractalResponse(transformer: ExistingTransformer::class)]
    public function ok(): void {}

    public function bare(): void {}
}

it('has the canonical rule metadata', function (): void {
    $rule = new FractalTransformerClassMissing();

    expect($rule->id)->toBe('fractal.transformer-class-missing')
        ->and($rule->severity)->toBe(Severity::Degraded)
        ->and($rule->description)->toContain('transformer class');
});

it('flags a #[FractalResponse] naming a missing transformer class', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(TransformerClassMissingController::class, 'typo');

    $rule = new FractalTransformerClassMissing();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('fractal.transformer-class-missing')
        ->and($findings[0]->message)->toContain('BookTrnasformer');
});

it('does not flag a #[FractalResponse] naming an existing transformer', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(TransformerClassMissingController::class, 'ok');

    $rule = new FractalTransformerClassMissing();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});

it('does not flag a method with no #[FractalResponse]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(TransformerClassMissingController::class, 'bare');

    $rule = new FractalTransformerClassMissing();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});
