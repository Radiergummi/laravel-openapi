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

/**
 * Reports response objects whose media-type content has no example.
 *
 * Examples on responses let API consumers and documentation generators show
 * what a real payload looks like. Responses that carry no content at all
 * (e.g. 204 No Content — no schema ref and no inline fields) are exempt.
 */
final class ResponseExampleMissing implements Rule, ResponseRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkResponse(ResponseNode $response, LintContext $context): iterable
    {
        // Responses with no content (no schema ref, no inline fields) have
        // nothing to illustrate — skip them (e.g. 204 No Content).
        if ($response->schemaRef === null && $response->fields === []) {
            return;
        }

        if ($response->examples !== []) {
            return;
        }

        $operation = $response->operation();

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Response %s on %s %s has no example',
                (string) $response->statusCode,
                $operation !== null ? $operation->method : '(unknown)',
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
    public function level(): int
    {
        return 4;
    }

    #[Override]
    public function description(): string
    {
        return 'Response media type has no example.';
    }
}
