<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Registry\RequestSchemaResolver;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Plugins\Core\Support\InlineValidationScanResult;
use Radiergummi\OpenApi\Plugins\Core\Support\InlineValidatorRulesReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\FakerExampleSynthesiser;
use Radiergummi\OpenApi\Support\Extraction\FieldDescriptor;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Registry\ResolvedSchema;
use ReflectionMethod;

use function implode;
use function in_array;
use function Radiergummi\OpenApi\is_undefined;
use function sprintf;
use function ucfirst;

/**
 * Core request-schema resolver
 *
 * Recovers a request body from validation rules declared inside the controller method —
 * inline `validate()` calls and the controller-level `$rules` property / `rules()` method —
 * via the Tier-1 bounded scan in {@see InlineValidatorRulesReader}. Registered after the
 * FormRequest resolver, so it only runs on a Tier-0 miss (no typed payload parameter); the
 * `#[RequestBody]` / `#[RequestField]` attributes still win unconditionally.
 *
 * Fires for write methods only: on GET, inline-validate keys describe query parameters, not a
 * body — that routing belongs to the query-parameter resolver (#11).
 */
#[Scoped]
final readonly class InlineValidationRequestSchemaResolver implements RequestSchemaResolver
{
    private const array WRITE_METHODS = [HttpMethod::Post, HttpMethod::Put, HttpMethod::Patch];

    public function __construct(
        private InlineValidatorRulesReader $reader,
        private ValidationRulesToSchema $rulesMapper,
        private ComponentSchemaRegistry $registry,
        private FakerExampleSynthesiser $synthesiser,
        private LoggerInterface $logger,
    ) {}

    public function resolveRequestSchema(ActionDescriptor $descriptor): ?ResolvedSchema
    {
        $method = $descriptor->method;

        if ($method === null || !in_array($descriptor->httpMethod, self::WRITE_METHODS, true)) {
            return null;
        }

        $scan = $this->reader->read($method);

        if ($scan === null) {
            return null;
        }

        $actionName = sprintf('%s::%s', $method->getDeclaringClass()->getName(), $method->getName());

        if ($scan->rules === null) {
            $this->logger->notice(
                sprintf(
                    'Inline validation in %s could not be read statically (%s); no request body inferred. '
                    . 'Annotate the action with #[RequestBody] / #[RequestField] to document it.',
                    $actionName,
                    $scan->degradeReason,
                ),
            );

            return null;
        }

        if ($scan->skippedFields !== []) {
            $this->logger->notice(
                sprintf(
                    'Inline validation in %s: dropped field(s) %s — their rules are not statically readable.',
                    $actionName,
                    implode(', ', $scan->skippedFields),
                ),
            );
        }

        [$schema, $containsFileField] = $this->buildSchema($scan, $method);

        $key = $this->componentKey($method);
        $this->registry->registerNamed($key, $schema);

        return new ResolvedSchema(
            componentKey: $key,
            mediaType: $containsFileField ? MediaType::MultipartFormData : MediaType::Json,
        );
    }

    /**
     * Mirrors the FormRequest schema assembly: rules → field descriptors → properties/required,
     * with synthesised examples as the lowest-priority fallback. Trailing-comment descriptions
     * are authored content and win over rule-derived ones.
     *
     * @return array{0: OA\Schema, 1: bool} the schema and whether any field is a file upload
     */
    private function buildSchema(InlineValidationScanResult $scan, ReflectionMethod $method): array
    {
        /** @var array<string, array<int, mixed>|string> $rules */
        $rules = $scan->rules ?? [];
        $mapped = $this->rulesMapper->process($rules, sourceClass: $method->getDeclaringClass()->getName());

        /** @var list<OA\Property> $properties */
        $properties = [];

        /** @var list<string> $required */
        $required = [];

        $containsFileField = false;

        foreach ($mapped['fields'] as $fieldName => $descriptor) {
            if (isset($scan->descriptions[$fieldName])) {
                $descriptor->description = $scan->descriptions[$fieldName];
            }

            $property = new OA\Property(['property' => $fieldName]);
            $descriptor->applyTo($property);

            if (is_undefined($property->example)) {
                $synthesised = $this->synthesiser->synthesise($fieldName, $descriptor);

                if ($synthesised !== null) {
                    $property->example = $synthesised;
                }
            }

            $properties[] = $property;

            if ($descriptor->required) {
                $required[] = $fieldName;
            }

            $containsFileField = $containsFileField || $this->containsFileField($descriptor);
        }

        $schemaProperties = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schemaProperties['required'] = $required;
        }

        if ($mapped['additionalPropertiesField'] !== null) {
            $additionalProperties = new OA\AdditionalProperties([]);
            $mapped['additionalPropertiesField']->applyTo($additionalProperties);
            $schemaProperties['additionalProperties'] = $additionalProperties;
        }

        return [new OA\Schema($schemaProperties), $containsFileField];
    }

    private function containsFileField(FieldDescriptor $descriptor): bool
    {
        if ($descriptor->isFile) {
            return true;
        }

        foreach ($descriptor->properties ?? [] as $nested) {
            if ($this->containsFileField($nested)) {
                return true;
            }
        }

        return $descriptor->items !== null && $this->containsFileField($descriptor->items);
    }

    /**
     * Derives the component key through the registry's collision machinery: reserving a
     * synthetic class-like name (`{ControllerNamespace}\{ControllerShortName}{Method}Request`)
     * makes {@see ComponentSchemaRegistry::reserveKey()} disambiguate same-short-name
     * controllers with namespace segments instead of silently sharing one schema. `__invoke`
     * contributes no method segment (`AgentControllerRequest`, not `AgentController__invokeRequest`).
     */
    private function componentKey(ReflectionMethod $method): string
    {
        $controller = $method->getDeclaringClass();
        $methodSegment = $method->getName() === '__invoke' ? '' : ucfirst($method->getName());
        $baseName = $controller->getShortName() . $methodSegment . 'Request';
        $namespace = $controller->getNamespaceName();

        return $this->registry->reserveKey($namespace === '' ? $baseName : "{$namespace}\\{$baseName}");
    }
}
