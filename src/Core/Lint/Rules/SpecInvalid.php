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
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ApiRule as ApiRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use InvalidArgumentException;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Override;
use RuntimeException;

use function array_slice;
use function file_exists;
use function file_get_contents;
use function implode;
use function in_array;
use function is_int;
use function json_decode;
use function resource_path;
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
        $this->schemaPath = $schemaPath ?? resource_path('openapi/oas-3.1-schema.json');
        $this->formatter = new ErrorFormatter();
    }

    #[Override]
    public function id(): string
    {
        return 'spec.invalid';
    }

    #[Override]
    public function level(): int
    {
        return 0;
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
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        if (!file_exists($this->schemaPath)) {
            throw new RuntimeException(
                "OpenAPI lint cannot run: OAS 3.1 meta-schema not found at {$this->schemaPath}",
            );
        }

        // Round-trip through JSON encode/decode to strip swagger-php's UNDEFINED
        // sentinel strings ("@OA\Generator::UNDEFINED🙈") that jsonSerialize()
        // leaves on unset properties. Without this, the validator sees those
        // sentinels as real values and emits false spec.invalid findings.
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

        // Index descriptors by normalized route for quick lookup
        $descriptorIndex = $this->buildDescriptorIndex($context->actionDescriptors);

        $seen = [];

        foreach ($this->extractLeafErrors($error) as $leafError) {
            if ($this->isFalsePositive($leafError)) {
                continue;
            }

            // spec.invalid is the catch-all — defer issues a dedicated rule
            // already reports so they aren't flagged twice.
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
     * Build an index of descriptors keyed by "METHOD /uri" for fast lookup.
     *
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
     * Detect known false positives from the OAS 3.1 meta-schema's $dynamicRef behavior, which
     * incorrectly propagates root-level constraints (like `required: ["openapi", "info"]`) to every
     * nested Schema Object.
     */
    private function isFalsePositive(ValidationError $error): bool
    {
        $path = $error->data()->fullPath();

        // "required" at the root is legitimate (spec must have openapi + info)
        if ($path === []) {
            return false;
        }

        // The classic false positive: "required" constraint with "openapi"/"info" at any nested
        // path, caused by $dynamicRef resolving to the root schema.
        if ($error->keyword() === 'required') {
            $args = $error->args();
            $missing = $args['missing'] ?? [];

            if (in_array('openapi', $missing, true) || in_array('info', $missing, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect spec errors that a more granular lint rule already reports, so
     * spec.invalid stays the catch-all for issues no dedicated rule covers.
     */
    private function isCoveredByGranularRule(ValidationError $error): bool
    {
        if ($error->keyword() !== 'required') {
            return false;
        }

        $missing = $error->args()['missing'] ?? [];
        $path = $error->data()->fullPath();
        $section = $path[0] ?? null;

        // A Response Object missing `description` is reported by
        // response.description-missing for operation and webhook responses.
        return $missing === ['description']
            && in_array($section, ['paths', 'webhooks'], true)
            && in_array('responses', $path, true);
    }

    /**
     * @param array<string, ActionDescriptor> $descriptorIndex
     */
    private function errorToFinding(ValidationError $error, array $descriptorIndex): Finding
    {
        $path = $error->data()->fullPath();
        $interpolatedMessage = $this->formatter->formatErrorMessage($error);
        $keyword = $error->keyword();

        $parsed = $this->parsePath($path);
        $jsonPointer = $this->buildJsonPointer($path);

        $descriptor = $this->resolveDescriptor($parsed, $descriptorIndex);
        $location = $this->buildLocation($parsed, $jsonPointer, $descriptor);
        $humanPath = $this->formatHumanPath($parsed, $descriptor);

        return new Finding(
            ruleId: 'spec.invalid',
            level: 0,
            message: "{$humanPath}: {$interpolatedMessage}",
            location: $location,
            fixHint: $this->buildFixHint($parsed, $keyword),
            context: ['keyword' => $keyword, 'json_pointer' => $jsonPointer],
        );
    }

    /**
     * Parse the raw path array into structured components.
     *
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
            // e.g. ["components", "schemas", "FooSchema", ...]
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
     * Build a proper RFC 6901 JSON Pointer (escaping ~ and /).
     *
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
     * Resolve the ActionDescriptor for a parsed path (if it points to a route).
     *
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
                routeMethod: strtoupper($parsed['method'] ?? ''),
                routeUri: $parsed['routeUri'],
                jsonPointer: $jsonPointer,
            );
        }

        return new FindingLocation(jsonPointer: $jsonPointer);
    }

    /**
     * Format a human-readable path, preferring the route name when available.
     *
     * Examples:
     *   "contacts.suggestions.apply → parameters[0].schema"
     *   "GET /api/v0/foo → requestBody.content.application/json.schema" (no route name)
     *   "components.schemas.FooSchema"
     *
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

        // Fallback: join all path segments
        $segments = array_merge([$parsed['section']], $parsed['trail']);

        return implode('.', $segments);
    }

    /**
     * Format trail segments into a readable dotted path with array indices, e.g.
     * ["parameters", 0, "schema"] → "parameters[0].schema"
     *
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
     * Build a contextual fix hint based on what part of the spec is invalid.
     *
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
