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
 * Reports when a Link annotation has neither `operationId` nor `operationRef` set. The OpenAPI
 * specification requires exactly one of the two.
 */
final class LinkNeitherOperationIdNorRef implements Rule, LinkRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkLink(LinkNode $link, LintContext $context): iterable
    {
        if ($link->operationId !== null || $link->operationRef !== null) {
            return;
        }

        $operation = $link->response()?->operation();
        $routeMethod = $operation?->method;
        $routeUri = $operation?->pathUri;
        $message = sprintf(
            'Link "%s" on %s %s has neither operationId nor operationRef',
            $link->name,
            $routeMethod?->forDisplay() ?? '?',
            $routeUri ?? '?',
        );

        yield new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
            message: $message,
            fixHint: 'Add either operationId (for same-document links) or operationRef (for cross-document links).',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'link.neither-operation-id-nor-ref';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Broken;
    }

    #[Override]
    public function description(): string
    {
        return 'Link has neither operationId nor operationRef.';
    }
}
