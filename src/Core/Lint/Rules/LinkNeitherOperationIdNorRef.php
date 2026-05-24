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
            $routeMethod ?? '?',
            $routeUri ?? '?',
        );

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
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
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Link has neither operationId nor operationRef.';
    }
}
