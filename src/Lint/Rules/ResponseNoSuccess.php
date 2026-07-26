<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function sprintf;

/**
 * Reports when an operation has responses defined but none of them is a 2xx success response.
 *
 * What the rule is really after is an operation that documents *failure only*. An operation
 * declaring the catch-all `default` has stated its outcome space on purpose (a `never`-returning
 * action, say), and one declaring a 3xx has documented its success path as a redirect. Neither is
 * underspecified, so both stay silent.
 */
final class ResponseNoSuccess implements Rule, OperationRuleVisitor
{
    public string $id = 'response.no-success';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'Operation documents only failure: no 2xx response, and no redirect '
        . 'or default response documenting the outcome another way.';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->responses === []) {
            return;
        }

        // A documented outcome that is not a 2xx: the catch-all, or a redirect (the redirect is the
        // success path). Either way the operation is not documenting failure only.
        if (array_any(
            $operation->responses,
            fn(ResponseNode $response): bool => $response->isDefault || $response->isRedirect,
        )) {
            return;
        }

        if ($operation->successResponses() === []) {
            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    'Operation %s %s has no 2xx success response',
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                ),
                fixHint: 'Add at least one success response (e.g., 200, 201, 204) to the operation.',
            );
        }
    }



}
