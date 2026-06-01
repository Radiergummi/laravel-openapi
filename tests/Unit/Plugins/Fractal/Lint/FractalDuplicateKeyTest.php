<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Lint;

use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalDuplicateKey;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use stdClass;

uses()->group('openapi', 'plugin:fractal');

#[TransformerField('id', type: 'integer')]
#[TransformerField('author', type: 'string')]
#[TransformerInclude('author', transformer: stdClass::class)]
class DuplicateKeyTransformer {}

#[TransformerField('id', type: 'integer')]
#[TransformerField('title', type: 'string')]
class UniqueKeyTransformer {}

class DuplicateKeyController
{
    #[FractalResponse(transformer: DuplicateKeyTransformer::class)]
    public function duplicate(): void {}

    #[FractalResponse(transformer: UniqueKeyTransformer::class)]
    public function unique(): void {}
}

it('flags a transformer with a name shared between #[TransformerField] and #[TransformerInclude]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(DuplicateKeyController::class, 'duplicate');

    $rule = new FractalDuplicateKey();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('fractal.duplicate-key')
        ->and($findings[0]->message)->toContain("'author'");
});

it('does not flag a transformer with unique output keys', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(DuplicateKeyController::class, 'unique');

    $rule = new FractalDuplicateKey();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});
