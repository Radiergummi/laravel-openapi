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
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\RequestBodyRule as RequestBodyRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\RequestBodyNode;

/**
 * Reports request body objects that declare no media-type entries, which means
 * the body can never carry a payload and the requestBody is effectively a no-op.
 */
final class RequestBodyNoContent implements Rule, RequestBodyRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkRequestBody(RequestBodyNode $requestBody, LintContext $context): iterable
    {
        if ($requestBody->contentTypes !== []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: 'A requestBody object has no media-type entries',
            fixHint: 'Add at least one content entry (e.g. application/json) with a schema.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'request-body.no-content';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'A requestBody object has no media-type entries.';
    }
}
