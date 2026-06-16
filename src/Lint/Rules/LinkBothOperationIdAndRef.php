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
 * Reports when a Link annotation has both `operationId` and `operationRef` set. The OpenAPI
 * specification requires exactly one of the two.
 */
final class LinkBothOperationIdAndRef implements Rule, LinkRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkLink(LinkNode $link, LintContext $context): iterable
    {
        if ($link->operationId === null || $link->operationRef === null) {
            return;
        }

        $operation = $link->response()?->operation();
        $routeMethod = $operation?->method;
        $routeUri = $operation?->pathUri;

        yield new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
            message: sprintf(
                'Link "%s" on %s %s sets both operationId ("%s") and operationRef ("%s"); only one is allowed',
                $link->name,
                $routeMethod?->forDisplay() ?? '?',
                $routeUri ?? '?',
                $link->operationId,
                $link->operationRef,
            ),
            fixHint: 'Remove either operationId or operationRef — the OpenAPI spec allows only one.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'link.both-operation-id-and-ref';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Broken;
    }

    #[Override]
    public function description(): string
    {
        return 'Link declares both operationId and operationRef (mutually exclusive).';
    }
}
