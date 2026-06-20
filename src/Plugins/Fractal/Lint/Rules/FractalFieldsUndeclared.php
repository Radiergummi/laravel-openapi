<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Support\TransformerTransformReader;
use ReflectionException;

use function class_exists;
use function sprintf;

/**
 * Flags an operation bound via `#[FractalResponse]` whose transformer declares no
 * `#[TransformerField]`, and whose shape the generator cannot infer from the `transform()`
 * literal either, so the response schema is genuinely empty.
 */
final class FractalFieldsUndeclared implements Rule, OperationRule
{
    public string $id = 'fractal.fields-undeclared';
    public Severity $severity = Severity::Degraded;
    public string $description = 'A transformer bound via #[FractalResponse] declares no #[TransformerField] attributes.';

    public function __construct(
        private readonly TransformerTransformReader $transformReader,
    ) {}

    /**
     * @return iterable<Finding>
     *
     * @throws ReflectionException
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $descriptor = $operation->descriptor;

        if ($operation->webhook || $descriptor === null) {
            return;
        }

        $attribute = $descriptor->actionAttributes(FractalResponse::class)[0] ?? null;

        if ($attribute === null) {
            return;
        }

        $transformer = $attribute->newInstance()->transformer;

        if (!class_exists($transformer)) {
            return;
        }

        if ($context->reflectionCache->classAttributes($transformer, TransformerField::class) !== []) {
            return;
        }

        $inferred = $this->transformReader->read($transformer);

        if ($inferred !== null && $inferred !== []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                '%s %s is bound to %s, which declares no #[TransformerField] — the response schema is empty',
                $operation->method->forDisplay(),
                $operation->pathUri,
                $transformer,
            ),
            fixHint: 'Declare each output key with a class-level #[TransformerField] on the transformer.',
        );
    }



}
