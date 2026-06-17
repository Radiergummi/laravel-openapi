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

use function is_array;
use function Radiergummi\OpenApi\is_defined;
use function sprintf;

/**
 * Reports parameters that have neither an `example` nor an `examples` value.
 *
 * Examples make generated documentation and mock servers immediately useful to API consumers.
 */
final class ParameterExampleMissing implements Rule, ParameterRuleVisitor
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

        $hasExample = is_defined($parameter->raw->example)
            && $parameter->raw->example !== null;

        $hasExamples = is_defined($parameter->raw->examples)
            && is_array($parameter->raw->examples)
            && $parameter->raw->examples !== [];

        if ($hasExample || $hasExamples) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
            message: sprintf('Parameter "%s" has no example value', $parameter->name),
            fixHint: 'Add an "example" or "examples" property to the parameter to improve documentation.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'parameter.example-missing';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Improvable;
    }

    #[Override]
    public function description(): string
    {
        return 'Parameter has no example.';
    }
}
