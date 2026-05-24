<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;

use function in_array;
use function sprintf;

/**
 * Reports operations that define a request body on GET or DELETE methods.
 *
 * While HTTP/1.1 does not strictly forbid request bodies on GET and DELETE, many server frameworks
 * and proxies ignore or reject them. Including a request body on these methods is a strong
 * convention violation.
 */
final class RequestBodyOnGetOrDelete implements Rule, OperationRuleVisitor
{
    /** @var list<string> */
    private const DISALLOWED_METHODS = ['GET', 'DELETE'];

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
                $operation->method,
                $operation->pathUri,
                $operation->method,
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
