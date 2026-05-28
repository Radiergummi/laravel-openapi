<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\LinkNode;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\LinkRule as LinkRuleVisitor;

use function array_key_exists;
use function sprintf;

/**
 * Reports when a Link doesn't supply a value for a parameter that the target operation requires.
 *
 * Limitation: only links that use `operationId` are validated. Links that reference their target
 * operation via `operationRef` are silently skipped because resolving an `operationRef` URI
 * (potentially an external document reference) is out of scope for this rule.
 */
final class LinkParameterRequiredMissing implements Rule, LinkRuleVisitor
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

        $targetOp = $context->index->operationsByOperationId[$link->operationId] ?? null;

        if ($targetOp === null) {
            return;
        }

        $requiredNames = $this->collectRequiredNames($targetOp);
        $operation = $link->response()?->operation();
        $routeMethod = $operation?->method;
        $routeUri = $operation?->pathUri;

        foreach ($requiredNames as $requiredName) {
            if (array_key_exists($requiredName, $link->parameters)) {
                continue;
            }

            $message = sprintf(
                'Link "%s" on %s %s does not supply required parameter "%s" for operation "%s"',
                $link->name,
                $routeMethod ?? '?',
                $routeUri ?? '?',
                $requiredName,
                $link->operationId,
            );

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: $message,
                fixHint: 'Add the missing required parameter to the Link parameters map.',
            );
        }
    }

    /**
     * Collect names of required parameters (path and query) from the target operation node.
     *
     * Path parameters are always required by the OpenAPI spec.
     *
     * @return list<string>
     */
    private function collectRequiredNames(OperationNode $targetOperation): array
    {
        $names = [];

        // Path parameters are always required
        foreach ($targetOperation->parameters as $param) {
            $names[] = $param->name;
        }

        // Query parameters are only required if explicitly marked
        foreach ($targetOperation->queryParameters as $parameter) {
            if ($parameter->required) {
                $names[] = $parameter->name;
            }
        }

        return $names;
    }

    #[Override]
    public function id(): string
    {
        return 'link.parameter-required-missing';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Link omits a parameter that the target operation requires.';
    }
}
