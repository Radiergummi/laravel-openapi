<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
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
 * Reports a Link's `parameters` entry that the target operation does not accept.
 *
 * Only `operationId`-based links are validated; `operationRef` links are skipped (resolving
 * a URI reference, potentially external, is out of scope).
 */
final class LinkInvalidParameter implements Rule, LinkRuleVisitor
{
    public string $id = 'link.invalid-parameter';
    public Severity $severity = Severity::Broken;
    public string $description = "Link references a parameter that the target operation doesn't declare.";

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
                ruleId: $this->id,
                severity: $this->severity,
                message: $message,
                fixHint: 'Remove the parameter or add it to the target operation.',
            );
        }
    }

    /**
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



}
