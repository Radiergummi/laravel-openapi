<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint;

use Override;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Contracts\Lint\NeedsInferenceDocument;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\InferenceView;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\Visitors\ComponentSchemaRule;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\RedundantOaAnnotationFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\OaRedundancyEngine;
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
 * Flags a hand-authored `#[OA\Schema]` / `@OA\Schema` annotation on a class whose component schema
 * the generator now reproduces on its own, so it can be deleted as the codebase moves onto inference.
 *
 * The verdict is provenance-based, not name-based: the class's authored schema (from the
 * {@see AuthoredAnnotationScanner}) is compared against inference's schema for the *same class*
 * ({@see InferenceView::schemaForClass()}), ignoring serialized names. It fires only when inference
 * **subsumes** the authored schema — reproduces everything the author wrote, and possibly more; a
 * description or restriction inference cannot derive keeps the annotation load-bearing. A schema
 * another surviving authored annotation still `$ref`s by name is never flagged, so the fix cannot
 * dangle a reference.
 *
 * Declares {@see NeedsInferenceDocument} so the runner builds the inference-only view once per spec,
 * only when the rule is active. Registered only by the off-by-default swagger-php plugin, at the
 * `migration.*` cleanup tier (level 4) — off ordinary runs until requested (`--only 'migration.*'`).
 *
 * @internal
 */
final class OaRedundantWithInference implements Rule, ComponentSchemaRule, FixableRule, NeedsInferenceDocument
{
    private readonly OaRedundancyEngine $engine;

    private readonly SchemaSubsumption $comparator;

    public function __construct(
        private readonly AuthoredAnnotationScanner $scanner,
        SchemaEquivalence $equivalence,
    ) {
        $this->engine = new OaRedundancyEngine();
        $this->comparator = new SchemaSubsumption($equivalence);
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

        // Removing a schema another surviving authored annotation still references by name would
        // dangle that reference.
        $isLoadBearing = fn(): bool
            => is_defined($authored->schema)
            && $this->scanner->isSchemaReferencedByOtherAuthored((string) $authored->schema, $class);

        $finding = $this->engine->evaluate(
            $authored,
            $inferred,
            $this->comparator,
            fn(): ReflectionClass => new ReflectionClass($class),
            fn(AuthoredAnnotationShape $shape): Finding => new Finding(
                ruleId: $this->id(),
                level: $this->level(),
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
            $isLoadBearing,
        );

        if ($finding !== null) {
            yield $finding;
        }
    }

    #[Override]
    public function id(): string
    {
        return 'migration.oa-redundant-with-inference';
    }

    #[Override]
    public function level(): int
    {
        // A redundant annotation is a cleanup opportunity, not a spec defect — the generated
        // document is correct with or without it.
        return 4;
    }

    #[Override]
    public function fixer(): Fixer
    {
        return new RedundantOaAnnotationFixer();
    }

    /**
     * The inference-only view this rule compares against is the document with the authored-annotation
     * harvest excluded — i.e. pure inference, no harvested schemas.
     *
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
        return 'A hand-authored #[OA\Schema] / @OA\Schema annotation the generator already reproduces via inference.';
    }
}
