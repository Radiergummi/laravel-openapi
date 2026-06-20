<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function sprintf;

/**
 * Reports when an operation has responses defined but none is a 4xx/5xx or `default` response.
 */
final class ResponseNoError implements Rule, OperationRuleVisitor
{
    public string $id = 'response.no-error';
    public Severity $severity = Severity::Degraded;
    public string $description = 'Operation has no error responses (4xx/5xx).';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $hasDefault = false;

        foreach ($operation->responses as $response) {
            if ($response->isDefault) {
                $hasDefault = true;

                break;
            }
        }

        if (
            $operation->responses !== []
            && !$hasDefault
            && $operation->errorResponses() === []
        ) {
            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    'Operation %s %s has no error response (4xx/5xx)',
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                ),
                fixHint: 'Add at least one error response (e.g., 400, 401, 404, 422, 500) to the operation.',
            );
        }
    }



}
