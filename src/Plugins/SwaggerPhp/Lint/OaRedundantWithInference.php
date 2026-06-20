<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint;

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
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\RedundantOaAnnotationFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\OaRedundancyEngine;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\ScannerInferenceRefResolver;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaEquivalence;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaSubsumption;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use ReflectionClass;
use ReflectionException;

use function class_exists;
use function Radiergummi\OpenApi\is_defined;
use function sprintf;

/**
 * Flags a hand-authored `#[OA\Schema]` / `@OA\Schema` whose schema the generator now reproduces
 * via inference, making the annotation redundant.
 *
 * Verdict is provenance-based: the authored schema is compared against the inferred schema for the
 * same class, and fires only when inference subsumes everything the author wrote. A schema still
 * `$ref`-ed by another surviving authored annotation is never flagged (avoids dangling refs).
 *
 * @internal
 */
final class OaRedundantWithInference implements Rule, ComponentSchemaRule, FixableRule, NeedsInferenceDocument
{
    public string $id = 'migration.oa-redundant-with-inference';
    public Severity $severity = Severity::Improvable;
    public string $description = 'A hand-authored #[OA\Schema] / @OA\Schema annotation the generator already reproduces via inference.';

    private readonly OaRedundancyEngine $engine;

    public function __construct(
        private readonly AuthoredAnnotationScanner $scanner,
    ) {
        $this->engine = new OaRedundancyEngine();
    }

    /**
     * @return iterable<Finding>
     *
     * @throws ReflectionException
     */
    #[Override]
    public function checkComponentSchema(ComponentSchemaNode $componentSchema, LintContext $context): iterable
    {
        $class = $componentSchema->sourceClass;

        if ($class === null || !class_exists($class)) {
            return;
        }

        $authored = $this->scanner->schemaForClass($class);

        if ($authored === null) {
            return;
        }

        $inferred = $context->inference->schemaForClass($class);

        // The oracle follows a $ref the author and convention named differently to its target schema
        // on each side; built per run since the inference view is per spec run.
        $comparator = new SchemaSubsumption(
            new SchemaEquivalence(new ScannerInferenceRefResolver($this->scanner, $context->inference)),
        );

        // Removing a schema still referenced by name by another authored annotation would dangle it.
        $isEssential = fn(): bool
            => is_defined($authored->schema)
            && $this->scanner->isSchemaReferencedByOtherAuthored($authored->schema, $class);

        $finding = $this->engine->evaluate(
            $authored,
            $inferred,
            $comparator,
            fn(): ReflectionClass => new ReflectionClass($class),
            fn(AuthoredAnnotationShape $shape): Finding => new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    'The %s annotation on %s restates a schema the generator already infers; it can be removed.',
                    $shape === AuthoredAnnotationShape::Docblock ? '@OA\Schema docblock' : '#[OA\Schema] attribute',
                    $class,
                ),
                fixHint: 'Remove the redundant swagger-php annotation; inference reproduces the same schema.',
                context: [
                    Finding::CONTEXT_SOURCE_CLASS => $class,
                    AuthoredAnnotationShape::FINDING_CONTEXT_KEY => $shape->value,
                ],
            ),
            $isEssential,
        );

        if ($finding !== null) {
            yield $finding;
        }
    }



    #[Override]
    public function fixer(): Fixer
    {
        return new RedundantOaAnnotationFixer();
    }

    /**
     * @return list<class-string<SpecStage>>
     */
    #[Override]
    public function excludedStages(): array
    {
        return [HarvestAuthoredAnnotationsStage::class];
    }

}
