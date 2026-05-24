<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ParameterRule as ParameterRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;

use function sprintf;
use function trim;

/**
 * Reports parameters that have no description.
 *
 * Parameters without descriptions make it harder for API consumers to understand what value to
 * provide and what effect it has.
 */
final class ParameterDescriptionMissing implements Rule, ParameterRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkParameter(ParameterNode $parameter, LintContext $context): iterable
    {
        if ($parameter->description !== null && trim($parameter->description) !== '') {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf('Parameter "%s" has no description', $parameter->name),
            fixHint: 'Add a description to the parameter explaining what value it accepts and its effect.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'parameter.description-missing';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'Parameter has no description.';
    }
}
