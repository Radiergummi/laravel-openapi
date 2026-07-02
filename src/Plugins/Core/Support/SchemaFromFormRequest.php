<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Support\Extraction\FakerExampleSynthesiser;
use Radiergummi\OpenApi\Support\Extraction\FieldDescriptor;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\ExplicitClassSchema;
use Radiergummi\OpenApi\Support\Provenance\SchemaProvenance;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionException;

use function array_any;
use function array_key_exists;
use function class_basename;
use function is_string;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;
use function sprintf;

/**
 * Builds an {@see OA\Schema} for a {@see FormRequest} by calling its `rules()` method via
 * {@see ValidationRulesToSchema}. Instantiation failures emit a placeholder schema and a warning
 * rather than aborting the generation run.
 */
#[Scoped]
final readonly class SchemaFromFormRequest
{
    public function __construct(
        private ValidationRulesToSchema $rulesMapper,
        private ComponentSchemaRegistry $registry,
        private LoggerInterface $logger,
        private FakerExampleSynthesiser $synthesiser,
        private FindingsCollector $findings,
        private ExplicitClassSchema $explicitSchema,
        private FormRequestRulesReader $rulesReader,
    ) {}

    /**
     * @param class-string<FormRequest> $formRequestClass
     */
    public function hasFileFields(string $formRequestClass): bool
    {
        // build() is registry-cached and populates hasFileFields as a side effect.
        $this->build($formRequestClass);

        return $this->registry->getHasFileFields($formRequestClass) ?? false;
    }

    /**
     * @param class-string<FormRequest> $formRequestClass
     */
    public function build(string $formRequestClass): OA\Schema
    {
        $key = $this->registry->buildOnce(
            $formRequestClass,
            fn(): OA\Schema => $this->buildSchema($formRequestClass),
            new SchemaProvenance(self::class),
        );

        return new OA\Schema(['ref' => $this->registry->qualifyKey($key)]);
    }

    /**
     * @param class-string<FormRequest> $formRequestClass
     *
     * @throws ReflectionException
     */
    private function buildSchema(string $formRequestClass): OA\Schema
    {
        $basename = class_basename($formRequestClass);

        // #[RawSchema] replaces the inferred body wholesale; the rules() read below is skipped.
        $reflection = new ReflectionClass($formRequestClass);

        if (($rawSchema = $this->explicitSchema->read($reflection)) !== null) {
            $this->registry->setHasFileFields($formRequestClass, false);

            return $this->explicitSchema->toSchema($rawSchema, $reflection);
        }

        if (!method_exists($formRequestClass, 'rules')) {
            $this->registry->setHasFileFields($formRequestClass, false);

            return new OA\Schema([
                'type' => 'object',
                'description' => sprintf(
                    '%s does not declare a rules() method.',
                    $basename,
                ),
            ]);
        }

        $rulesResult = $this->rulesReader->read($formRequestClass);

        if ($rulesResult->rules === null) {
            $this->logger->warning(
                sprintf(
                    'SchemaFromFormRequest failed for %s: %s',
                    $formRequestClass,
                    $rulesResult->degradeReason,
                ),
            );

            $this->emitDegradedFinding($formRequestClass, $rulesResult->degradeReason);
            $this->registry->setHasFileFields($formRequestClass, false);

            return new OA\Schema([
                'type' => 'object',
                'description' => sprintf(
                    'Schema introspection failed for %s.',
                    $basename,
                ),
            ]);
        }

        $result = $this->rulesMapper->process($rulesResult->rules, sourceClass: $formRequestClass);
        $fieldMap = $result['fields'];
        $additionalPropertiesField = $result['additionalPropertiesField'];

        $this->registry->setHasFileFields(
            $formRequestClass,
            array_any($fieldMap, static fn(FieldDescriptor $d): bool => $d->isFile),
        );

        $constantOverrides = $this->readConstantFieldAttributes($formRequestClass);

        /** @var list<OA\Property> $properties */
        $properties = [];

        /** @var list<string> $required */
        $required = [];

        foreach ($fieldMap as $fieldName => $descriptor) {
            $property = $this->buildProperty($fieldName, $descriptor);

            if (array_key_exists($fieldName, $constantOverrides)) {
                $constantOverrides[$fieldName]->descriptor()->applyTo($property);
            }

            // Use the effective type/format (may be overridden by #[RequestField] on a PARAM_*
            // constant) rather than the rules-derived values when synthesising an example.
            if (is_undefined($property->example)) {
                if (is_string($property->type) && is_defined($property->type)) {
                    $descriptor->type = $property->type;
                }

                if (is_string($property->format) && is_defined($property->format)) {
                    $descriptor->format = $property->format;
                }

                $synthesised = $this->synthesiser->synthesise($fieldName, $descriptor);

                if ($synthesised !== null) {
                    $property->example = $synthesised;
                }
            }

            $properties[] = $property;

            if ($descriptor->required) {
                $required[] = $fieldName;
            }
        }

        $schemaProps = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schemaProps['required'] = $required;
        }

        if ($additionalPropertiesField !== null) {
            $additionalProperties = new OA\AdditionalProperties([]);
            $additionalPropertiesField->applyTo($additionalProperties);
            $schemaProps['additionalProperties'] = $additionalProperties;
        }

        return new OA\Schema($schemaProps);
    }

    /**
     * @param class-string<FormRequest> $formRequestClass
     */
    private function emitDegradedFinding(string $formRequestClass, ?string $reason): void
    {
        $file = null;
        $line = null;

        try {
            $reflection = new ReflectionClass($formRequestClass);
            $file = $reflection->getFileName() ?: null;
            $line = $reflection->getStartLine() ?: null;
        } catch (ReflectionException) {
            // Non-fatal: the finding is still useful without file/line.
        }

        $this->findings->emit(
            new Finding(
                ruleId: 'request-body.schema-degraded',
                severity: Severity::Degraded,
                message: sprintf(
                    'Schema introspection failed for %s: %s',
                    $formRequestClass,
                    $reason,
                ),
                location: new FindingLocation(file: $file, line: $line),
                fixHint: 'rules() threw during introspection. Common causes: a type-check against runtime state (e.g., `instanceof User`), a call into a container service that is not bound at spec-time, or a `match`/`switch` on a runtime value. Refactor rules() to depend only on the request payload, or suppress this finding on the FormRequest class with `#[IgnoreLint(\'request-body.schema-degraded\', reason: \'…\')]` and document the limitation in the API description.',
                context: [Finding::CONTEXT_SOURCE_CLASS => $formRequestClass],
            ),
        );
    }

    /**
     * @param class-string<FormRequest> $formRequestClass
     *
     * @return array<string, FieldAttribute>
     *
     * @throws ReflectionException
     */
    private function readConstantFieldAttributes(string $formRequestClass): array
    {
        $reflection = new ReflectionClass($formRequestClass);
        $out = [];

        foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $attrs = $constant->getAttributes(
                FieldAttribute::class,
                ReflectionAttribute::IS_INSTANCEOF,
            );

            if ($attrs === []) {
                continue;
            }

            $fieldName = $constant->getValue();

            if (!is_string($fieldName)) {
                continue;
            }

            $out[$fieldName] = $attrs[0]->newInstance();
        }

        return $out;
    }

    private function buildProperty(string $name, FieldDescriptor $d): OA\Property
    {
        $property = new OA\Property(['property' => $name]);
        $d->applyTo($property);

        return $property;
    }
}
