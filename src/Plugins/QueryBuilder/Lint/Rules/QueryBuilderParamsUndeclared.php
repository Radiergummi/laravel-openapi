<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;

use function in_array;
use function sprintf;

/**
 * Flags a controller method that injects a `spatie/laravel-query-builder` `QueryBuilder` but
 * declares none of `#[AllowedFilter]`, `#[AllowedSort]`, or `#[AllowedInclude]` — the endpoint
 * accepts filter/sort/include parameters that the generated document does not describe.
 *
 * Detection is deliberately conservative: it keys off an injected `QueryBuilder` parameter
 * (matched by FQCN string via {@see PayloadParameterScanner}, so the package need not be
 * installed), not a body-inference heuristic.
 */
final readonly class QueryBuilderParamsUndeclared implements Rule, OperationRule
{
    private const string QUERY_BUILDER_CLASS = 'Spatie\\QueryBuilder\\QueryBuilder';

    public function __construct(
        private PayloadParameterScanner $scanner,
    ) {}

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $descriptor = $operation->descriptor;
        $method = $descriptor?->method;

        if ($operation->webhook || $descriptor === null || $method === null) {
            return;
        }

        if (!in_array(self::QUERY_BUILDER_CLASS, $this->scanner->directCandidates($method), true)) {
            return;
        }

        $hasAttributes = $descriptor->actionAttributes(AllowedFilter::class) !== []
            || $descriptor->actionAttributes(AllowedSort::class) !== []
            || $descriptor->actionAttributes(AllowedInclude::class) !== [];

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
