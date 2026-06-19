<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint;

use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Contracts\Lint\NeedsInferenceDocument;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\Visitors\ComponentSchemaRule;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\RedundantOaPropertyFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\DetectsPropertyShape;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\ScannerInferenceRefResolver;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaEquivalence;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use ReflectionException;

use function class_exists;
use function is_array;
use function is_subclass_of;
use function Radiergummi\OpenApi\is_defined;
use function sprintf;

/**
 * Flags an individual hand-authored member `OA\Property` on a Spatie Data class whose schema
 * inference already reproduces, leaving the enclosing schema and its load-bearing siblings in place.
 *
 * The per-member analog of {@see OaRedundantWithInference}: where the whole-class rule fires only
 * when inference subsumes the *entire* authored schema, this flags the individual members inference
 * subsumes, so a class mixing redundant (type-only) and load-bearing (`description`-carrying) member
 * annotations no longer escapes the migration entirely.
 *
 * Scope is Spatie Data members only: their emitted component is produced by inference and named by
 * the PHP class, not by the harvester, so a subsumed member `OA\Property` is pure redundant authoring
 * whose removal provably cannot mutate the emitted schema. API-Resource / request-DTO member
 * `OA\Property` lives inside the class-level `#[OA\Schema]` the harvester emits, where per-member
 * removal would mutate the emitted component, so it is out of scope.
 *
 * @internal
 */
final readonly class OaRedundantPropertyWithInference implements Rule, ComponentSchemaRule, FixableRule, NeedsInferenceDocument
{
    use DetectsPropertyShape;

    public function __construct(
        private AuthoredAnnotationScanner $scanner,
    ) {}

    /**
     * @return iterable<Finding>
     *
     * @throws ReflectionException
     */
    #[Override]
    public function checkComponentSchema(ComponentSchemaNode $componentSchema, LintContext $context): iterable
    {
        $class = $componentSchema->sourceClass;

        if ($class === null || !class_exists($class) || !$this->isDataClass($class)) {
            return;
        }

        $authored = $this->scanner->schemaForClass($class);

        // Removing a member from a schema another authored annotation still $refs by name could mutate
        // the harvester-emitted component under that name, so the whole class is skipped conservatively.
        if (
            $authored !== null
            && is_defined($authored->schema)
            && $this->scanner->isSchemaReferencedByOtherAuthored($authored->schema, $class)
        ) {
            return;
        }

        $inferred = $context->inference->schemaForClass($class);
        $inferredProperties = $this->propertiesByName($inferred);

        // The oracle follows a $ref the author and convention named differently on each side; built
        // per run since the inference view is per spec run.
        $equivalence = new SchemaEquivalence(
            new ScannerInferenceRefResolver($this->scanner, $context->inference),
        );

        foreach ($this->scanner->propertiesForClass($class) as $propertyName => $authoredProperty) {
            $inferredProperty = $inferredProperties[$propertyName] ?? null;

            if ($inferredProperty === null || !$equivalence->subsumes($inferredProperty, $authoredProperty)) {
                continue;
            }

            $shape = $this->propertyShape($class, $propertyName);

            if ($shape === null) {
                continue;
            }

            yield $this->finding($class, $propertyName, $shape);
        }
    }

    /**
     * @param class-string $class
     */
    private function isDataClass(string $class): bool
    {
        return class_exists('Spatie\\LaravelData\\Data') && is_subclass_of($class, 'Spatie\\LaravelData\\Data');
    }

    /**
     * The schema's `OA\Property` annotations keyed by their output `property` name.
     *
     * @return array<string, OA\Property>
     */
    private function propertiesByName(?OA\Schema $schema): array
    {
        if ($schema === null || !is_array($schema->properties)) {
            return [];
        }

        $byName = [];

        foreach ($schema->properties as $property) {
            if ($property instanceof OA\Property && is_defined($property->property)) {
                $byName[$property->property] = $property;
            }
        }

        return $byName;
    }

    private function finding(string $class, string $propertyName, AuthoredAnnotationShape $shape): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
            message: sprintf(
                'The %s on %s::$%s restates a property the generator already infers; it can be removed.',
                $shape === AuthoredAnnotationShape::Docblock ? '@OA\Property docblock' : '#[OA\Property] attribute',
                $class,
                $propertyName,
            ),
            fixHint: 'Remove the redundant member swagger-php annotation; inference reproduces the same property.',
            context: [
                Finding::CONTEXT_SOURCE_CLASS => $class,
                Finding::CONTEXT_SOURCE_MEMBER => $propertyName,
                AuthoredAnnotationShape::FINDING_CONTEXT_KEY => $shape->value,
            ],
        );
    }

    #[Override]
    public function id(): string
    {
        return 'migration.oa-redundant-property-with-inference';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Improvable;
    }

    #[Override]
    public function fixer(): Fixer
    {
        return new RedundantOaPropertyFixer();
    }

    /**
     * @return list<class-string<SpecStage>>
     */
    #[Override]
    public function excludedStages(): array
    {
        return [HarvestAuthoredAnnotationsStage::class];
    }

    #[Override]
    public function description(): string
    {
        return 'A hand-authored member OA\Property on a Spatie Data class the generator already infers.';
    }
}
