<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ParameterRule as ParameterRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\QueryParameterRule as QueryParameterRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Core\Lint\Tree\QueryParameterNode;

use function in_array;
use function sprintf;
use function str_contains;

/**
 * Reports path and query parameter names that do not follow the configured naming convention.
 *
 * Default: {@see IdentifierCase::Snake} (e.g. `project_id`).
 *
 * Query parameter exclusions (framework-generated, not author-controlled):
 * - Names containing `[` — JSON:API bracket notation such as `filter[id]` or `page[number]`.
 * - The exact names `page`, `per_page`, `sort`, and `include` — standard JSON:API / Spatie
 *   QueryBuilder parameters injected by the request layer.
 */
#[Scoped]
final readonly class ParameterNameNamingInconsistent extends AbstractNamingRule implements
    ParameterRuleVisitor,
    QueryParameterRuleVisitor
{
    /** @var list<string> */
    private const array FRAMEWORK_QUERY_PARAMS = ['page', 'per_page', 'sort', 'include'];

    public function __construct(
        #[Config('openapi.lint.style.parameter_name_case', 'snake')]
        IdentifierCase|string $case = IdentifierCase::Snake,
    ) {
        parent::__construct($case);
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkParameter(ParameterNode $parameter, LintContext $context): iterable
    {
        if ($this->conforms($parameter->name)) {
            return;
        }

        yield $this->finding($parameter->name);
    }

    private function finding(string $name): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Parameter name "%s" does not follow the %s naming convention',
                $name,
                $this->case->label(),
            ),
            fixHint: $this->fixHint('parameter names'),
        );
    }

    #[Override]
    public function id(): string
    {
        return 'parameter.name-naming-inconsistent';
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkQueryParameter(QueryParameterNode $queryParameter, LintContext $context): iterable
    {
        if ($this->isExcludedQueryParam($queryParameter->name)) {
            return;
        }

        if ($this->conforms($queryParameter->name)) {
            return;
        }

        yield $this->finding($queryParameter->name);
    }

    private function isExcludedQueryParam(string $name): bool
    {
        if (str_contains($name, '[')) {
            return true;
        }

        return in_array($name, self::FRAMEWORK_QUERY_PARAMS, strict: true);
    }

    #[Override]
    public function description(): string
    {
        return "Parameter name doesn't follow the project's parameter_name_case convention.";
    }
}
