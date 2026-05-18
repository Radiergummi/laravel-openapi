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

/**
 * Reports request bodies whose media-type content has no example.
 *
 * Examples on request bodies let API consumers copy-paste a working payload
 * instead of guessing the expected structure from the schema alone.
 */
final class RequestBodyExampleMissing implements Rule, RequestBodyRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkRequestBody(RequestBodyNode $requestBody, LintContext $context): iterable
    {
        if ($requestBody->examples !== []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: 'Request body has no example value',
            fixHint: 'Add an "examples" entry to the requestBody media type to illustrate the expected payload.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'request-body.example-missing';
    }

    #[Override]
    public function level(): int
    {
        return 4;
    }

    #[Override]
    public function description(): string
    {
        return 'requestBody has no example.';
    }
}
