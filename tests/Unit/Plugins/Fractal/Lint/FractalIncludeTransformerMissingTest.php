<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Lint;

use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalIncludeTransformerMissing;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use stdClass;

uses()->group('openapi', 'plugin:fractal');

#[TransformerField('id', type: 'integer')]
#[TransformerInclude('comments')]
class IncludelessTransformer {}

#[TransformerField('id', type: 'integer')]
#[TransformerInclude('author', transformer: stdClass::class)]
class FullyBoundIncludeTransformer {}

class IncludeLintController
{
    #[FractalResponse(transformer: IncludelessTransformer::class)]
    public function bare(): void {}

    #[FractalResponse(transformer: FullyBoundIncludeTransformer::class)]
    public function bound(): void {}
}

it('flags a #[TransformerInclude] with no transformer class', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(IncludeLintController::class, 'bare');

    $rule = new FractalIncludeTransformerMissing();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('fractal.include-transformer-missing')
        ->and($findings[0]->message)->toContain('comments');
});

it('does not flag an include that names a transformer', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(IncludeLintController::class, 'bound');

    $rule = new FractalIncludeTransformerMissing();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});
