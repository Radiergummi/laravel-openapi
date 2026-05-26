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
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ResponseRule as ResponseRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;

use function in_array;
use function sprintf;

/**
 * Reports 2xx responses that advertise no body schema.
 *
 * Most often caused by an action whose return type is `void` or untyped: the generator
 * produces a 200 with empty content, and clients regenerated against the spec break.
 * Codes that the HTTP spec defines as bodiless (204, 205, 304) are skipped, as are HEAD
 * operations, whose bodies are intentionally suppressed.
 */
final class ResponseSuccessEmptyBody implements Rule, ResponseRuleVisitor
{
    /**
     * Status codes for which an empty body is correct per RFC 9110 / RFC 7232.
     *
     * @var list<int|string>
     */
    private const array BODILESS_CODES = [204, 205, 304, '204', '205', '304'];

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkResponse(ResponseNode $response, LintContext $context): iterable
    {
        if (!$response->isSuccess()) {
            return;
        }

        if (in_array($response->statusCode, self::BODILESS_CODES, true)) {
            return;
        }

        if ($response->operation()?->method === 'HEAD') {
            return;
        }

        if ($response->fields !== [] || $response->schemaRef !== null) {
            return;
        }

        $operation = $response->operation();
        $route = $operation !== null
            ? sprintf('%s %s', $operation->method, $operation->pathUri)
            : '<unknown operation>';

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Response %s on %s has no body schema',
                $response->statusCode,
                $route,
            ),
            fixHint: 'Type the action\'s return value (Data class, API Resource, FormRequest), or change the status code to 204 if the response is intentionally empty.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'response.success-empty-body';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'A 2xx response (other than 204/205/304) declares no body schema. Likely a void-return controller.';
    }
}
