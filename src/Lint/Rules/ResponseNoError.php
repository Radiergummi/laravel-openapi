<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function sprintf;

/**
 * Reports when an operation has responses defined but none of them is a 4xx or 5xx error response.
 *
 * Consumers need error responses documented to plan error handling. A `default`
 * response counts as error coverage, since it applies to every status code not
 * otherwise listed.
 */
final class ResponseNoError implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $hasDefault = false;

        foreach ($operation->responses as $response) {
            if ($response->isDefault()) {
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
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Operation %s %s has no error response (4xx/5xx)',
                    $operation->method,
                    $operation->pathUri,
                ),
                fixHint: 'Add at least one error response (e.g. 400, 401, 404, 422, 500) to the operation.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'response.no-error';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation has no error responses (4xx/5xx).';
    }
}
