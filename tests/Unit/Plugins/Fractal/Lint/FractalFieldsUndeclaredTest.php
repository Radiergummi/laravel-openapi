<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Lint;

use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalFieldsUndeclared;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:fractal');

class BareFractalTransformer {}

#[TransformerField('id', type: 'integer')]
class PopulatedFractalTransformer {}

class BareFractalController
{
    #[FractalResponse(transformer: BareFractalTransformer::class)]
    public function bare(): void {}

    #[FractalResponse(transformer: PopulatedFractalTransformer::class)]
    public function populated(): void {}

    public function unbound(): void {}
}

it('flags a #[FractalResponse] transformer that declares no #[TransformerField]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(BareFractalController::class, 'bare');

    $rule = new FractalFieldsUndeclared();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('fractal.fields-undeclared');
});

it('does not flag a transformer that declares #[TransformerField]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(BareFractalController::class, 'populated');

    $rule = new FractalFieldsUndeclared();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});

it('does not flag a method without #[FractalResponse]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(BareFractalController::class, 'unbound');

    $rule = new FractalFieldsUndeclared();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});
