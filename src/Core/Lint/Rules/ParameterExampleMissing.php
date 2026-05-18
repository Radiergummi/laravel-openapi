<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ParameterRule as ParameterRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;
use OpenApi\Generator;
use Override;

use function is_array;
use function sprintf;

/**
 * Reports parameters that have neither an `example` nor an `examples` value.
 *
 * Examples on parameters help API consumers understand what value to supply
 * and make generated documentation and mock servers immediately useful.
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

        $hasExample = $parameter->raw->example !== Generator::UNDEFINED
            && $parameter->raw->example !== null;

        $hasExamples = $parameter->raw->examples !== Generator::UNDEFINED
            && is_array($parameter->raw->examples)
            && $parameter->raw->examples !== [];

        if ($hasExample || $hasExamples) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
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
    public function level(): int
    {
        return 4;
    }

    #[Override]
    public function description(): string
    {
        return 'Parameter has no example.';
    }
}
