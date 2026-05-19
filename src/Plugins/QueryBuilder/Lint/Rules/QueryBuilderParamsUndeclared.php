<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use ReflectionMethod;
use ReflectionNamedType;

use function sprintf;

/**
 * Flags a controller method that injects a `spatie/laravel-query-builder`
 * `QueryBuilder` but declares none of `#[AllowedFilter]`, `#[AllowedSort]`, or
 * `#[AllowedInclude]` — the endpoint accepts filter/sort/include parameters
 * that the generated document does not describe.
 *
 * Detection is deliberately conservative: it keys off an injected `QueryBuilder`
 * parameter (matched by FQCN string, so the package need not be installed),
 * not a body-inference heuristic.
 */
final readonly class QueryBuilderParamsUndeclared implements Rule, OperationRule
{
    private const string QUERY_BUILDER_CLASS = 'Spatie\\QueryBuilder\\QueryBuilder';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $method = $operation->descriptor?->method;

        if ($operation->webhook || $method === null) {
            return;
        }

        if (!$this->injectsQueryBuilder($method)) {
            return;
        }

        $hasAttributes = $method->getAttributes(AllowedFilter::class) !== []
            || $method->getAttributes(AllowedSort::class) !== []
            || $method->getAttributes(AllowedInclude::class) !== [];

        if ($hasAttributes) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s %s injects a QueryBuilder but declares no #[AllowedFilter]/#[AllowedSort]/#[AllowedInclude]',
                $operation->method,
                $operation->pathUri,
            ),
            fixHint: 'Declare the accepted parameters with #[AllowedFilter], #[AllowedSort], and #[AllowedInclude].',
        );
    }

    private function injectsQueryBuilder(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === self::QUERY_BUILDER_CLASS) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function id(): string
    {
        return 'query-builder.params-undeclared';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'A method injects a QueryBuilder but declares no allowed filter/sort/include attributes.';
    }
}
