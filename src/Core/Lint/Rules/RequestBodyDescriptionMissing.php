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
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\RequestBodyRule as RequestBodyRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\RequestBodyNode;
use Override;

use function trim;

/**
 * Reports request bodies that have no description.
 *
 * A description on the request body helps consumers understand the overall
 * purpose and structure of the payload they must supply.
 */
final class RequestBodyDescriptionMissing implements Rule, RequestBodyRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkRequestBody(RequestBodyNode $requestBody, LintContext $context): iterable
    {
        if ($requestBody->description !== null && trim($requestBody->description) !== '') {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: 'Request body has no description',
            fixHint: 'Add a description to the requestBody explaining the expected payload.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'request-body.description-missing';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'requestBody has no description.';
    }
}
