<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\LinkRule as LinkRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\LinkNode;

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
            level: $this->level(),
            message: sprintf(
                'Link "%s" on %s %s sets both operationId ("%s") and operationRef ("%s"); only one is allowed',
                $link->name,
                $routeMethod ?? '?',
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
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Link declares both operationId and operationRef (mutually exclusive).';
    }
}
