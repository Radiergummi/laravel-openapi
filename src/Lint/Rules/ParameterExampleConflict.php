<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Lint\Visitors\ParameterRule as ParameterRuleVisitor;

use function sprintf;

/**
 * Reports parameters that set both `example` (singular) and `examples` (plural).
 *
 * The OpenAPI specification states these two fields are mutually exclusive. Having both present
 * on a parameter object is a spec violation that causes ambiguity for documentation and
 * code-generation tooling.
 */
final class ParameterExampleConflict implements Rule, ParameterRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkParameter(ParameterNode $parameter, LintContext $context): iterable
    {
        if ($parameter->raw === null) {
            return;
        }

        $hasSingular = !Generator::isDefault($parameter->raw->example);
        $hasPlural = !Generator::isDefault($parameter->raw->examples);

        if (!$hasSingular || !$hasPlural) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Parameter "%s" sets both "example" and "examples", which are mutually exclusive.',
                $parameter->name,
            ),
            fixHint: 'Remove either "example" or "examples" — only one may be present on a parameter.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'parameter.example-conflict';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'A parameter sets both example and examples (mutually exclusive).';
    }
}
