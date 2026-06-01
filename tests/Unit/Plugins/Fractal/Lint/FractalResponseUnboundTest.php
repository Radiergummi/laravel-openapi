<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Lint;

use League\Fractal\Manager;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalResponseUnbound;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use stdClass;

uses()->group('openapi', 'plugin:fractal');

/*
 * A stand-in for `League\Fractal\Manager`. The rule matches the type name as a string, so the
 * fixture method below declares the real FQCN via a class_alias so the test does not require the
 * package.
 */
if (!class_exists('League\\Fractal\\Manager')) {
    class_alias(stdClass::class, 'League\\Fractal\\Manager');
}

class UnboundFractalTransformer {}

class FractalUnboundController
{
    public function unbound(Manager $fractal): void {}

    #[FractalResponse(transformer: UnboundFractalTransformer::class)]
    public function bound(Manager $fractal): void {}

    public function plain(): void {}
}

it('flags a method injecting Fractal Manager with no #[FractalResponse]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(FractalUnboundController::class, 'unbound');

    $rule = new FractalResponseUnbound(new PayloadParameterScanner());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('fractal.response-unbound');
});

it('does not flag a method that declares #[FractalResponse]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(FractalUnboundController::class, 'bound');

    $rule = new FractalResponseUnbound(new PayloadParameterScanner());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});

it('does not flag a method that does not inject a Manager', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(FractalUnboundController::class, 'plain');

    $rule = new FractalResponseUnbound(new PayloadParameterScanner());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});
