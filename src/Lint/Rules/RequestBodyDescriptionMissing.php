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

use function trim;

/**
 * Reports request bodies that have no description.
 */
final class RequestBodyDescriptionMissing implements Rule, RequestBodyRuleVisitor
{
    public string $id = 'request-body.description-missing';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'requestBody has no description.';

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
            ruleId: $this->id,
            severity: $this->severity,
            message: 'Request body has no description',
            fixHint: 'Add a description to the requestBody explaining the expected payload.',
        );
    }



}
