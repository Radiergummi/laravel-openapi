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

/**
 * Reports response objects whose media-type content has no example.
 * Responses that carry no content at all (e.g., 204 No Content) are exempt.
 */
final class ResponseExampleMissing implements Rule, ResponseRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkResponse(ResponseNode $response, LintContext $context): iterable
    {
        if ($response->schemaRef === null && $response->fields === []) {
            return;
        }

        if ($response->examples !== []) {
            return;
        }

        $operation = $response->operation();

        yield new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
            message: sprintf(
                'Response %d on %s %s has no example',
                $response->statusCode,
                $operation !== null ? $operation->method->forDisplay() : '(unknown)',
                $operation !== null ? $operation->pathUri : '(unknown)',
            ),
            fixHint: 'Add an "examples" entry to the response media type to illustrate the expected payload.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'response.example-missing';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Improvable;
    }

    #[Override]
    public function description(): string
    {
        return 'Response media type has no example.';
    }
}
