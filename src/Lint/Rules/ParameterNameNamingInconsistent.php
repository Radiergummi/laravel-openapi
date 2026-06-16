<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\IdentifierCase;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Lint\Visitors\ParameterRule as ParameterRuleVisitor;
use Radiergummi\OpenApi\Lint\Visitors\QueryParameterRule as QueryParameterRuleVisitor;

use function in_array;
use function preg_match;
use function sprintf;
use function str_contains;

/**
 * Reports path and query parameter names that do not follow the configured naming convention.
 *
 * Path parameters default to camelCase; query parameters default to snake_case.
 * Framework-injected query params (`page`, `per_page`, `sort`, `include`, bracket notation)
 * are excluded.
 */
#[Scoped]
final readonly class ParameterNameNamingInconsistent extends AbstractNamingRule implements
    ParameterRuleVisitor,
    QueryParameterRuleVisitor
{
    /** @var list<string> */
    private const array FRAMEWORK_QUERY_PARAMS = ['page', 'per_page', 'sort', 'include'];

    private IdentifierCase $pathCase;

    private IdentifierCase $queryCase;

    public function __construct(
        #[Config('openapi.lint.style.path_parameter_case', 'camel')]
        IdentifierCase|string $pathCase = IdentifierCase::Camel,
        #[Config('openapi.lint.style.query_parameter_case', 'snake')]
        IdentifierCase|string $queryCase = IdentifierCase::Snake,
    ) {
        $this->pathCase = IdentifierCase::fromConfig($pathCase);
        $this->queryCase = IdentifierCase::fromConfig($queryCase);

        parent::__construct($this->pathCase);
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkParameter(ParameterNode $parameter, LintContext $context): iterable
    {
        if (preg_match($this->pathCase->pattern(), $parameter->name) === 1) {
            return;
        }

        yield $this->finding($parameter->name, $this->pathCase);
    }

    private function finding(string $name, IdentifierCase $case): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
            message: sprintf(
                'Parameter name "%s" does not follow the %s naming convention',
                $name,
                $case->label(),
            ),
            fixHint: sprintf(
                'Use %s for parameter names (e.g., %s).',
                $case->label(),
                $case->example(),
            ),
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

        if (preg_match($this->queryCase->pattern(), $queryParameter->name) === 1) {
            return;
        }

        yield $this->finding($queryParameter->name, $this->queryCase);
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
        return "Parameter name doesn't follow the project's path_parameter_case / query_parameter_case convention.";
    }
}
