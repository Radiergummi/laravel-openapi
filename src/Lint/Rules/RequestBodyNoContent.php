<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\RequestBodyNode;
use Radiergummi\OpenApi\Lint\Visitors\RequestBodyRule as RequestBodyRuleVisitor;

/**
 * Reports request body objects that declare no media-type entries, which means
 * the body can never carry a payload and the requestBody is effectively a no-op.
 */
final class RequestBodyNoContent implements Rule, RequestBodyRuleVisitor
{
    public string $id = 'request-body.no-content';
    public Severity $severity = Severity::Degraded;
    public string $description = 'A requestBody object has no media-type entries.';

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
            ruleId: $this->id,
            severity: $this->severity,
            message: 'A requestBody object has no media-type entries',
            fixHint: 'Add at least one content entry (e.g., application/json) with a schema.',
        );
    }



}
