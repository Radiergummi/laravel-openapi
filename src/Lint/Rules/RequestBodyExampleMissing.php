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
 * Reports request bodies whose media-type content has no example.
 */
final class RequestBodyExampleMissing implements Rule, RequestBodyRuleVisitor
{
    public string $id = 'request-body.example-missing';
    public Severity $severity = Severity::Improvable;
    public string $description = 'requestBody has no example.';

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
            ruleId: $this->id,
            severity: $this->severity,
            message: 'Request body has no example value',
            fixHint: 'Add an "examples" entry to the requestBody media type to illustrate the expected payload.',
        );
    }



}
