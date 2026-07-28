<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Lint\Visitors\ResponseRule as ResponseRuleVisitor;

use function in_array;
use function is_array;
use function Radiergummi\OpenApi\is_defined;
use function sprintf;

/**
 * Reports 2xx responses that advertise no body schema.
 * Bodiless codes (204, 205, 304) and HEAD operations are exempt.
 */
final class ResponseSuccessEmptyBody implements Rule, ResponseRuleVisitor
{
    public string $id = 'response.success-empty-body';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'A 2xx response (other than 204/205/304) declares no body schema. Likely a void-return controller.';

    /** @var list<int|string> */
    private const array BODILESS_CODES = [204, 205, 304, '204', '205', '304'];

    /**
     * Schema keywords that say something about the body's shape. A media-type schema carrying any
     * of them describes a body, even when it has no properties to become fields.
     *
     * @var list<string>
     */
    private const array SHAPE_KEYWORDS = ['type', 'allOf', 'anyOf', 'oneOf'];

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

        // Scalar bodies (a plain string payload, a binary download) have neither fields nor a
        // component reference, so the raw media-type schema is the only place they surface.
        if ($this->declaresBodySchema($response->raw)) {
            return;
        }

        $operation = $response->operation();
        $route = $operation !== null
            ? sprintf('%s %s', $operation->method->forDisplay(), $operation->pathUri)
            : '<unknown operation>';

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Response %s on %s has no body schema',
                $response->statusCode,
                $route,
            ),
            fixHint: 'Type the action\'s return value (Data class, API Resource, FormRequest), or change the status code to 204 if the response is intentionally empty.',
        );
    }

    /** Whether any of the response's media types declares a schema describing a body. */
    private function declaresBodySchema(?OA\Response $response): bool
    {
        if ($response === null) {
            return false;
        }

        // @phpstan-ignore nullCoalesce.property (swagger-php may leave property unset at runtime)
        $content = $response->content ?? Generator::UNDEFINED;

        if (!is_array($content)) {
            return false;
        }

        foreach ($content as $mediaType) {
            if (!$mediaType instanceof OA\MediaType) {
                continue;
            }

            $schema = $mediaType->schema;

            if ($schema instanceof OA\Schema && $this->declaresShape($schema)) {
                return true;
            }
        }

        return false;
    }

    private function declaresShape(OA\Schema $schema): bool
    {
        foreach (self::SHAPE_KEYWORDS as $keyword) {
            if (is_defined($schema->{$keyword} ?? Generator::UNDEFINED)) {
                return true;
            }
        }

        return false;
    }
}
