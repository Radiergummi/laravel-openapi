<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use InvalidArgumentException;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule as ApiRuleVisitor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use RuntimeException;

use function array_slice;
use function array_values;
use function dirname;
use function file_exists;
use function file_get_contents;
use function implode;
use function in_array;
use function is_int;
use function json_decode;
use function sprintf;
use function str_contains;
use function strtoupper;

use const JSON_THROW_ON_ERROR;

final readonly class SpecInvalid implements Rule, ApiRuleVisitor
{
    private string $schemaPath;

    private ErrorFormatter $formatter;

    public function __construct(?string $schemaPath = null)
    {
        $this->schemaPath = $schemaPath ?? dirname(__DIR__, 3) . '/resources/openapi/oas-3.1-schema.json';
        $this->formatter = new ErrorFormatter();
    }

    #[Override]
    public function id(): string
    {
        return 'spec.invalid';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Broken;
    }

    #[Override]
    public function description(): string
    {
        return 'Spec fails swagger-php validation. Cannot be suppressed or remapped.';
    }

    /**
     * @return iterable<Finding>
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws RuntimeException         if the OAS 3.1 meta-schema file is missing
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        if (!file_exists($this->schemaPath)) {
            throw new RuntimeException(
                "OpenAPI lint cannot run: OAS 3.1 meta-schema not found at {$this->schemaPath}",
            );
        }

        // Strip swagger-php's UNDEFINED sentinel strings that jsonSerialize() leaves on unset
        // properties; without this the validator sees them as real values and emits false positives.
        $specJson = json_decode(
            json_encode($context->rawSpec->jsonSerialize(), JSON_THROW_ON_ERROR),
            flags: JSON_THROW_ON_ERROR,
        );
        $schemaJson = json_decode(
            (string) file_get_contents($this->schemaPath),
            flags: JSON_THROW_ON_ERROR,
        );

        $validator = new Validator();
        $validator->setMaxErrors(50);

        $result = $validator->validate($specJson, $schemaJson);

        if ($result->isValid()) {
            return;
        }

        $error = $result->error();

        if ($error === null) {
            return;
        }

        $descriptorIndex = $this->buildDescriptorIndex($context->actionDescriptors);

        $seen = [];

        foreach ($this->extractLeafErrors($error) as $leafError) {
            if ($this->isFalsePositive($leafError)) {
                continue;
            }

            if ($this->isCoveredByGranularRule($leafError)) {
                continue;
            }

            $finding = $this->errorToFinding($leafError, $descriptorIndex);
            $key = sprintf('%s|%s', $finding->location->jsonPointer ?? '', $finding->message);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            yield $finding;
        }
    }

    /**
     * @param list<ActionDescriptor> $descriptors
     *
     * @return array<string, ActionDescriptor>
     */
    private function buildDescriptorIndex(array $descriptors): array
    {
        $index = [];

        foreach ($descriptors as $descriptor) {
            $uri = '/' . ltrim($descriptor->route->uri(), '/');

            foreach ($descriptor->route->methods() as $method) {
                $method = strtoupper($method);

                if ($method === 'HEAD') {
                    continue;
                }

                $index["{$method} {$uri}"] = $descriptor;
            }
        }

        return $index;
    }

    /**
     * @return iterable<ValidationError>
     */
    private function extractLeafErrors(ValidationError $error): iterable
    {
        $subErrors = $error->subErrors();

        if ($subErrors === []) {
            yield $error;

            return;
        }

        foreach ($subErrors as $subError) {
            yield from $this->extractLeafErrors($subError);
        }
    }

    /**
     * Detect known false positives from the OAS 3.1 meta-schema's $dynamicRef behavior,
     * which incorrectly propagates root-level constraints to nested Schema Objects.
     */
    private function isFalsePositive(ValidationError $error): bool
    {
        $path = $error->data()->fullPath();

        // Root-level "required" is legitimate (openapi + info are genuinely required there).
        if ($path === []) {
            return false;
        }

        // "openapi"/"info" required at a nested path means $dynamicRef resolved to the root schema.
        if ($error->keyword() === 'required') {
            $args = $error->args();
            $missing = $args['missing'] ?? [];

            if (in_array('openapi', $missing, true) || in_array('info', $missing, true)) {
                return true;
            }
        }

        return false;
    }

    private function isCoveredByGranularRule(ValidationError $error): bool
    {
        if ($error->keyword() !== 'required') {
            return false;
        }

        $missing = $error->args()['missing'] ?? [];
        $path = $error->data()->fullPath();
        $section = $path[0] ?? null;

        return $missing === ['description']
            && in_array($section, ['paths', 'webhooks'], true)
            && in_array('responses', $path, true);
    }

    /**
     * @param array<string, ActionDescriptor> $descriptorIndex
     */
    private function errorToFinding(ValidationError $error, array $descriptorIndex): Finding
    {
        $path = array_values($error->data()->fullPath());
        $interpolatedMessage = $this->formatter->formatErrorMessage($error);
        $keyword = $error->keyword();

        $parsed = $this->parsePath($path);
        $jsonPointer = $this->buildJsonPointer($path);

        $descriptor = $this->resolveDescriptor($parsed, $descriptorIndex);
        $location = $this->buildLocation($parsed, $jsonPointer, $descriptor);
        $humanPath = $this->formatHumanPath($parsed, $descriptor);

        return new Finding(
            ruleId: 'spec.invalid',
            severity: $this->severity(),
            message: "{$humanPath}: {$interpolatedMessage}",
            location: $location,
            fixHint: $this->buildFixHint($parsed, $keyword),
            context: ['keyword' => $keyword, 'json_pointer' => $jsonPointer],
        );
    }

    /**
     * @param list<int|string> $path
     *
     * @return array{section: string, routeUri: ?string, method: ?string, trail: list<int|string>}
     */
    private function parsePath(array $path): array
    {
        $section = (string) ($path[0] ?? '');
        $routeUri = null;
        $method = null;

        if ($section === 'paths' && isset($path[1])) {
            $routeUri = (string) $path[1];
            $method = isset($path[2]) ? (string) $path[2] : null;
            $trail = array_slice($path, 3);
        } elseif ($section === 'components' && isset($path[1], $path[2])) {
            $trail = array_slice($path, 1);
        } else {
            $trail = array_slice($path, 1);
        }

        return [
            'section' => $section,
            'routeUri' => $routeUri,
            'method' => $method,
            'trail' => $trail,
        ];
    }

    /**
     * @param list<int|string> $path
     */
    private function buildJsonPointer(array $path): string
    {
        if ($path === []) {
            return '/';
        }

        $encoded = '';

        foreach ($path as $segment) {
            $segment = str_replace(['~', '/'], ['~0', '~1'], (string) $segment);
            $encoded .= '/' . $segment;
        }

        return $encoded;
    }

    /**
     * @param array{
     *     section: string,
     *     routeUri: ?string,
     *     method: ?string,
     *     trail: list<int|string>
     * }                                      $parsed
     * @param array<string, ActionDescriptor> $descriptorIndex
     */
    private function resolveDescriptor(array $parsed, array $descriptorIndex): ?ActionDescriptor
    {
        if (
            $parsed['section'] !== 'paths'
            || $parsed['routeUri'] === null
            || $parsed['method'] === null
        ) {
            return null;
        }

        $key = strtoupper($parsed['method']) . ' ' . $parsed['routeUri'];

        return $descriptorIndex[$key] ?? null;
    }

    /**
     * @param array{section: string, routeUri: ?string, method: ?string, trail: list<int|string>} $parsed
     */
    private function buildLocation(
        array $parsed,
        string $jsonPointer,
        ?ActionDescriptor $descriptor,
    ): FindingLocation {
        if ($descriptor !== null && $descriptor->method !== null) {
            return new FindingLocation(
                file: $descriptor->method->getFileName() ?: null,
                line: $descriptor->method->getStartLine() ?: null,
                routeName: $descriptor->route->getName(),
                routeMethod: HttpMethod::fromString($parsed['method'] ?? ''),
                routeUri: $parsed['routeUri'],
                jsonPointer: $jsonPointer,
            );
        }

        return new FindingLocation(jsonPointer: $jsonPointer);
    }

    /**
     * @param array{
     *     section: string,
     *     routeUri: ?string,
     *     method: ?string,
     *     trail: list<int|string>
     * } $parsed
     */
    private function formatHumanPath(array $parsed, ?ActionDescriptor $descriptor = null): string
    {
        if ($parsed['section'] === 'paths' && $parsed['routeUri'] !== null) {
            $routeName = $descriptor?->route->getName();

            $prefix
                = $routeName
                ?? ($parsed['method'] !== null
                    ? strtoupper($parsed['method']) . ' ' . $parsed['routeUri']
                    : $parsed['routeUri']);

            $trailStr = $this->formatTrail($parsed['trail']);

            return $trailStr !== '' ? "{$prefix} → {$trailStr}" : $prefix;
        }

        if ($parsed['section'] === 'components') {
            $trailStr = implode('.', $parsed['trail']);

            return "components.{$trailStr}";
        }

        $segments = array_merge([$parsed['section']], $parsed['trail']);

        return implode('.', $segments);
    }

    /**
     * @param list<int|string> $trail
     */
    private function formatTrail(array $trail): string
    {
        if ($trail === []) {
            return '';
        }

        $parts = '';

        foreach ($trail as $i => $segment) {
            if (is_int($segment)) {
                $parts .= "[{$segment}]";
            } elseif ($i === 0) {
                $parts .= $segment;
            } else {
                $parts .= ".{$segment}";
            }
        }

        return $parts;
    }

    /**
     * @param array{
     *     section: string,
     *     routeUri: ?string,
     *     method: ?string,
     *     trail: list<int|string>
     * } $parsed
     */
    private function buildFixHint(array $parsed, string $keyword): string
    {
        return match ($this->detectComponent($parsed['trail'])) {
            'parameter_schema' => 'Check the route parameter type-hint or #[QueryParam] type.'
                . ' The generated schema for this parameter is invalid.',
            'request_schema' => 'Check the Data class or FormRequest backing this endpoint.'
                . ' A property type or validation rule may produce an invalid schema.',
            'response_schema' => 'Check the ApiResource or #[Response(schema: [...])] for this endpoint.'
                . ' The response schema is structurally invalid.',
            'example' => 'The example value does not conform to the schema structure.'
                . ' Verify the example payload matches the expected format.',
            default => "The generated spec is structurally invalid at this location ({$keyword} constraint)."
                . ' Review the attributes and type-hints on the source method.',
        };
    }

    /**
     * @param list<int|string> $trail
     */
    private function detectComponent(array $trail): string
    {
        $trailStr = implode('/', $trail);

        if (str_contains($trailStr, 'parameters') && str_contains($trailStr, 'schema')) {
            return 'parameter_schema';
        }

        if (str_contains($trailStr, 'requestBody') && str_contains($trailStr, 'schema')) {
            return 'request_schema';
        }

        if (str_contains($trailStr, 'responses') && str_contains($trailStr, 'schema')) {
            return 'response_schema';
        }

        if (str_contains($trailStr, 'example')) {
            return 'example';
        }

        return 'unknown';
    }
}
