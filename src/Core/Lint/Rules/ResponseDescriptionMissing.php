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
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ResponseRule as ResponseRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Override;

use function sprintf;
use function trim;

/**
 * Reports response objects that have no description.
 *
 * The OpenAPI 3.1 specification requires every Response Object to include a `description` field.
 * Missing descriptions make it harder for API consumers to understand what each status code means
 * for a given operation.
 */
final class ResponseDescriptionMissing implements Rule, ResponseRuleVisitor
{
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
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Response %s on %s %s has no description',
                (string) $response->statusCode,
                $operation !== null ? $operation->method : '(unknown)',
                $operation !== null ? $operation->pathUri : '(unknown)',
            ),
            fixHint: 'Add a description to the response object as required by the OpenAPI specification.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'response.description-missing';
    }

    #[Override]
    public function description(): string
    {
        return 'Response has no description. OAS 3.1 requires description on every Response Object.';
    }

    #[Override]
    public function level(): int
    {
        // The OpenAPI 3.1 spec requires `description` on every Response Object —
        // a missing one is a correctness error, not a documentation nicety.
        return 0;
    }
}
