<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Lint\Visitors\ParameterRule as ParameterRuleVisitor;

use function Radiergummi\OpenApi\is_defined;
use function sprintf;

/**
 * Reports parameters that set both `example` and `examples`, which the OpenAPI spec treats as
 * mutually exclusive.
 */
final class ParameterExampleConflict implements Rule, ParameterRuleVisitor
{
    public string $id = 'parameter.example-conflict';
    public Severity $severity = Severity::Degraded;
    public string $description = 'A parameter sets both example and examples (mutually exclusive).';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkParameter(ParameterNode $parameter, LintContext $context): iterable
    {
        if ($parameter->raw === null) {
            return;
        }

        $hasSingular = is_defined($parameter->raw->example);
        $hasPlural = is_defined($parameter->raw->examples);

        if (!$hasSingular || !$hasPlural) {
            return;
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Parameter "%s" sets both "example" and "examples", which are mutually exclusive.',
                $parameter->name,
            ),
            fixHint: 'Remove either "example" or "examples" — only one may be present on a parameter.',
        );
    }



}
