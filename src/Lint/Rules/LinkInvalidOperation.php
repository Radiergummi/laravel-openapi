<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\LinkNode;
use Radiergummi\OpenApi\Lint\Visitors\LinkRule as LinkRuleVisitor;

use function sprintf;

/**
 * Reports links whose `operationId` references an operation that does not exist in the document.
 */
final class LinkInvalidOperation implements Rule, LinkRuleVisitor
{
    public string $id = 'link.invalid-operation';
    public Severity $severity = Severity::Degraded;
    public string $description = "Link references an operationId that doesn't exist in the document.";

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkLink(LinkNode $link, LintContext $context): iterable
    {
        if ($link->operationId === null) {
            return;
        }

        if (isset($context->index->operationsByOperationId[$link->operationId])) {
            return;
        }

        $operation = $link->response()?->operation();
        $routeMethod = $operation?->method;
        $routeUri = $operation?->pathUri;
        $message = sprintf(
            'Link "%s" on %s %s references unknown operationId "%s"',
            $link->name,
            $routeMethod?->forDisplay() ?? '?',
            $routeUri ?? '?',
            $link->operationId,
        );

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: $message,
            fixHint: 'Fix the operationId, or use operationRef for cross-document links.',
        );
    }



}
