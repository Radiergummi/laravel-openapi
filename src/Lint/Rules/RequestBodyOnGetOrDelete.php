<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function in_array;
use function sprintf;

/**
 * Reports GET or DELETE operations that define a request body.
 *
 * Many frameworks and proxies ignore or reject bodies on these methods.
 */
final class RequestBodyOnGetOrDelete implements Rule, OperationRuleVisitor
{
    /** @var list<HttpMethod> */
    private const array DISALLOWED_METHODS = [HttpMethod::Get, HttpMethod::Delete];

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->requestBody === null) {
            return;
        }

        if (!in_array($operation->method, self::DISALLOWED_METHODS, true)) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Operation %s %s defines a request body, which is unconventional for %s requests',
                $operation->method->forDisplay(),
                $operation->pathUri,
                $operation->method->forDisplay(),
            ),
            fixHint: 'Move parameters to query string or path parameters instead of using a request body.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'request-body.on-get-or-delete';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'GET or DELETE operation has a request body.';
    }
}
