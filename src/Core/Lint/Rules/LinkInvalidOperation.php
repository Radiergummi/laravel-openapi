<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\LinkRule as LinkRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\LinkNode;
use Override;

use function sprintf;

final class LinkInvalidOperation implements Rule, LinkRuleVisitor
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

        if (isset($context->index->operationsByOperationId[$link->operationId])) {
            return;
        }

        $operation = $link->response()?->operation();
        $routeMethod = $operation?->method;
        $routeUri = $operation?->pathUri;
        $message = sprintf(
            'Link "%s" on %s %s references unknown operationId "%s"',
            $link->name,
            $routeMethod ?? '?',
            $routeUri ?? '?',
            $link->operationId,
        );

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: $message,
            fixHint: 'Fix the operationId, or use operationRef for cross-document links.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'link.invalid-operation';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return "Link references an operationId that doesn't exist in the document.";
    }
}
