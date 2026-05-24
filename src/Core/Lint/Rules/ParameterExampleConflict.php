<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ParameterRule as ParameterRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;

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

        $hasSingular = $parameter->raw->example !== Generator::UNDEFINED;
        $hasPlural = $parameter->raw->examples !== Generator::UNDEFINED;

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
