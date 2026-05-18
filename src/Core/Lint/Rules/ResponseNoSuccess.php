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
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Override;

use function sprintf;

/**
 * Reports when an operation has responses defined but none of them is a 2xx success response.
 */
final class ResponseNoSuccess implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->responses === []) {
            return;
        }

        foreach ($operation->responses as $response) {
            if ($response->isDefault()) {
                return;
            }
        }

        if ($operation->successResponses() === []) {
            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Operation %s %s has no 2xx success response',
                    $operation->method,
                    $operation->pathUri,
                ),
                fixHint: 'Add at least one success response (e.g. 200, 201, 204) to the operation.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'response.no-success';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation has no 2xx response.';
    }
}
