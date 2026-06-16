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

use function in_array;
use function sprintf;

/**
 * Reports 2xx responses that advertise no body schema.
 * Bodiless codes (204, 205, 304) and HEAD operations are exempt.
 */
final class ResponseSuccessEmptyBody implements Rule, ResponseRuleVisitor
{
    /** @var list<int|string> */
    private const array BODILESS_CODES = [204, 205, 304, '204', '205', '304'];

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkResponse(ResponseNode $response, LintContext $context): iterable
    {
        if (!$response->isSuccess) {
            return;
        }

        if (in_array($response->statusCode, self::BODILESS_CODES, true)) {
            return;
        }

        if ($response->operation()?->method === HttpMethod::Head) {
            return;
        }

        if ($response->fields !== [] || $response->schemaRef !== null) {
            return;
        }

        $operation = $response->operation();
        $route = $operation !== null
            ? sprintf('%s %s', $operation->method->forDisplay(), $operation->pathUri)
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
