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

use function class_exists;
use function sprintf;

/**
 * Flags a `#[FractalResponse]` whose `transformer:` argument names a class that does not exist,
 * typically a typo (`BookTrnasformer::class`). The `FractalResponseResolver` logs a warning and
 * returns null in this case, so the operation silently loses its 200 response. This rule
 * surfaces it during `openapi:lint` instead.
 */
final readonly class FractalTransformerClassMissing implements Rule, OperationRule
{
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

        if (class_exists($transformer)) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
            message: sprintf(
                '#[FractalResponse] on %s %s names unknown transformer %s',
                $operation->method->forDisplay(),
                $operation->pathUri,
                $transformer,
            ),
            fixHint: 'Check the transformer class name for typos and ensure it is autoloadable.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'fractal.transformer-class-missing';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Degraded;
    }

    #[Override]
    public function description(): string
    {
        return 'A #[FractalResponse] names a transformer class that does not exist.';
    }
}
