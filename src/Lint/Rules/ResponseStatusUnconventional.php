<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Lint\Visitors\ResponseRule as ResponseRuleVisitor;

use function sprintf;

/**
 * Reports when a POST operation only declares 200 (should prefer 201) or a DELETE operation only
 * declares 200 (should prefer 204) as its success status.
 */
final class ResponseStatusUnconventional implements Rule, ResponseRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkResponse(ResponseNode $response, LintContext $context): iterable
    {
        if ((string) $response->statusCode !== '200') {
            return;
        }

        $operation = $response->operation();

        if ($operation === null) {
            return;
        }

        $method = $operation->method;

        if ($method !== HttpMethod::Post && $method !== HttpMethod::Delete) {
            return;
        }

        // Only emit if the ONLY 2xx response on the operation is 200
        $successCodes = [];

        foreach ($operation->responses as $operationResponse) {
            if ($operationResponse->isSuccess) {
                $successCodes[] = (string) $operationResponse->statusCode;
            }
        }

        if ($successCodes !== ['200']) {
            return;
        }

        // Returning the deleted/created resource as a body in 200 is valid REST — only flag
        // when the 200 response carries no schema/body (truly empty success response).
        if ($response->fields !== [] || $response->schemaRef !== null) {
            return;
        }

        $expected = $method === HttpMethod::Post ? '201' : '204';

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Operation %s %s uses status 200 as its only success response; consider using %s instead',
                $method->forDisplay(),
                $operation->pathUri,
                $expected,
            ),
            fixHint: sprintf(
                '%s operations should typically return %s instead of 200.',
                $method->forDisplay(),
                $expected,
            ),
        );
    }

    #[Override]
    public function id(): string
    {
        return 'response.status-unconventional';
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'Response uses a status code that is unusual for the HTTP method.';
    }
}
