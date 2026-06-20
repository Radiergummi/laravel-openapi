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
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;

use function class_exists;
use function sprintf;

/**
 * Flags a `#[TransformerInclude]` declared with no `transformer` class; the included resource
 * is emitted as an opaque `type: object`.
 */
final class FractalIncludeTransformerMissing implements Rule, OperationRule
{
    public string $id = 'fractal.include-transformer-missing';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'A #[TransformerInclude] is declared without a transformer class.';

    /**
     * @return iterable<Finding>
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

        foreach (
            $context->reflectionCache->classAttributes(
                $transformer,
                TransformerInclude::class,
            ) as $includeAttribute
        ) {
            $include = $includeAttribute->newInstance();

            if ($include->transformer !== null) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    '#[TransformerInclude(\'%s\')] on %s has no transformer — the include is emitted as an opaque object',
                    $include->name,
                    $transformer,
                ),
                fixHint: 'Add transformer: to #[TransformerInclude] naming the included resource\'s transformer.',
            );
        }
    }



}
