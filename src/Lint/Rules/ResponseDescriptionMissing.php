<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Lint\Visitors\ResponseRule as ResponseRuleVisitor;

use function sprintf;
use function trim;

/**
 * Reports response objects that have no description. OAS 3.1 requires a `description` on every
 * Response Object.
 */
final class ResponseDescriptionMissing implements Rule, ResponseRuleVisitor
{
    public string $id = 'response.description-missing';
    public Severity $severity = Severity::Broken;
    public string $description = 'Response has no description. OAS 3.1 requires description on every Response Object.';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkResponse(ResponseNode $response, LintContext $context): iterable
    {
        if ($response->description !== null && trim($response->description) !== '') {
            return;
        }

        $operation = $response->operation();

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Response %d on %s %s has no description',
                $response->statusCode,
                $operation !== null ? $operation->method->forDisplay() : '(unknown)',
                $operation !== null ? $operation->pathUri : '(unknown)',
            ),
            fixHint: 'Add a description to the response object as required by the OpenAPI specification.',
        );
    }



}
