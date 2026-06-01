<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Lint\Visitors\ResponseRule as ResponseRuleVisitor;

use function sprintf;
use function strtolower;

/**
 * Reports 3xx redirect responses that do not declare a Location header.
 *
 * Redirect responses should include a Location header so that clients know where to follow
 * the redirect.
 */
final class ResponseRedirectWithoutLocation implements Rule, ResponseRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkResponse(ResponseNode $response, LintContext $context): iterable
    {
        if (!$response->isRedirect()) {
            return;
        }

        if (array_any($response->headers, fn($header) => strtolower($header->name) === 'location')) {
            return;
        }

        $operation = $response->operation();

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Redirect response %d on %s %s has no Location header',
                $response->statusCode,
                $operation !== null ? $operation->method : '(unknown)',
                $operation !== null ? $operation->pathUri : '(unknown)',
            ),
            fixHint: 'Add a Location header to the redirect response so clients know where to follow.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'response.redirect-without-location';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return '3xx response has no Location header.';
    }
}
