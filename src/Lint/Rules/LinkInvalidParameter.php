<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\LinkNode;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Lint\Visitors\LinkRule as LinkRuleVisitor;

use function array_flip;
use function array_keys;
use function array_map;
use function sprintf;

/**
 * Reports when a Link's `parameters` map references a parameter name that the target operation does
 * not accept (neither as a path nor query parameter).
 *
 * Limitation: only links that use `operationId` are validated. Links that reference their target
 * operation via `operationRef` are silently skipped because resolving an `operationRef` URI
 * (potentially an external document reference) is out of scope for this rule.
 */
final class LinkInvalidParameter implements Rule, LinkRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkLink(LinkNode $link, LintContext $context): iterable
    {
        if ($link->operationId === null) {
            return;
        }

        if ($link->parameters === []) {
            return;
        }

        $targetOperation = $context->index->operationsByOperationId[$link->operationId] ?? null;

        // Target operation doesn't exist - that's LinkInvalidOperation's job
        if ($targetOperation === null) {
            return;
        }

        $acceptedNames = $this->collectAcceptedNames($targetOperation);
        $operation = $link->response()?->operation();
        $routeMethod = $operation?->method;
        $routeUri = $operation?->pathUri;

        foreach (array_keys($link->parameters) as $paramName) {
            if (isset($acceptedNames[$paramName])) {
                continue;
            }

            $message = sprintf(
                'Link "%s" on %s %s passes parameter "%s" which is not accepted by operation "%s"',
                $link->name,
                $routeMethod?->forDisplay() ?? '?',
                $routeUri ?? '?',
                $paramName,
                $link->operationId,
            );

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: $message,
                fixHint: 'Remove the parameter or add it to the target operation.',
            );
        }
    }

    /**
     * Collect accepted parameter names (path and query) from the target operation node,
     * returned as a set (keys are names, values are 1) for O(1) membership testing.
     *
     * @return array<string, int>
     */
    private function collectAcceptedNames(OperationNode $targetOperation): array
    {
        return array_flip(
            array_map(
                static fn(ParameterNode|QueryParameterNode $param): string => $param->name,
                [...$targetOperation->parameters, ...$targetOperation->queryParameters],
            ),
        );
    }

    #[Override]
    public function id(): string
    {
        return 'link.invalid-parameter';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return "Link references a parameter that the target operation doesn't declare.";
    }
}
