<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint;

use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\Visitors\ComponentSchemaRule;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\RedundantOaAnnotationFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredSchemaShape;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\InferenceControlDocument;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaEquivalence;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use ReflectionClass;
use ReflectionException;

use function class_exists;
use function Radiergummi\OpenApi\is_defined;
use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * Flags a hand-authored `#[OA\Schema]` / `@OA\Schema` annotation on a class whose component schema
 * the generator now reproduces on its own, so the annotation can be deleted as the codebase moves
 * onto inference.
 *
 * The verdict is provenance-based, not name-based: the class's authored schema (from the
 * {@see AuthoredAnnotationScanner}) is compared against inference's schema for the *same class* in
 * an {@see InferenceControlDocument}, ignoring whatever names either side serializes to. It fires
 * only when inference **subsumes** the authored schema — reproduces everything the author wrote and
 * possibly more (a synthesised example, a discovered property). A description or restriction
 * inference cannot derive keeps the annotation load-bearing, so it stays.
 *
 * A schema another surviving authored annotation still `$ref`s by name is never flagged, so the fix
 * cannot leave a dangling reference.
 *
 * Registered only by the (off-by-default) swagger-php plugin. As a `migration.*` rule it sits at
 * the cleanup tier (level 4), so it stays off ordinary runs — and its second, inference-only
 * generation stays unpaid — until explicitly requested (`openapi:lint --only 'migration.*'`) or run
 * at a high level; disable the family with `--skip 'migration.*'`.
 *
 * @internal
 */
final class OaRedundantWithInference implements Rule, ComponentSchemaRule, FixableRule
{
    /** swagger-php's PHP-attribute namespace (`#[OA\Schema]` etc.), distinct from `@OA` docblocks. */
    private const string ATTRIBUTE_NAMESPACE = 'OpenApi\\Attributes\\';

    public const string CONTEXT_SHAPE = 'oaAnnotationShape';

    public function __construct(
        private readonly AuthoredAnnotationScanner $scanner,
        private readonly InferenceControlDocument $control,
        private readonly SchemaEquivalence $equivalence,
    ) {}

    /**
     * @return iterable<Finding>
     *
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    #[Override]
    public function checkComponentSchema(ComponentSchemaNode $componentSchema, LintContext $context): iterable
    {
        $class = $componentSchema->sourceClass;

        // No source class, or no spec to build the control against: we cannot decide redundancy.
        if ($class === null || $context->specName === null || !class_exists($class)) {
            return;
        }

        $authored = $this->scanner->schemaForClass($class);

        if ($authored === null) {
            return;
        }

        $inferred = $this->control->schemaForClass($context->specName, $class);

        // Inference produces no schema for this class: the annotation is load-bearing — keep it.
        if ($inferred === null) {
            return;
        }

        // Fire only when inference reproduces everything the author wrote (and possibly more).
        if (!$this->equivalence->subsumes($inferred, $authored)) {
            return;
        }

        // Removing a schema another surviving authored annotation still references by name would
        // dangle that reference.
        if (is_defined($authored->schema)
            && $this->scanner->isSchemaReferencedByOtherAuthored((string) $authored->schema, $class)
        ) {
            return;
        }

        $shape = $this->shapeFor($class);

        if ($shape === null) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'The %s annotation on %s restates a schema the generator already infers; it can be removed.',
                $shape === AuthoredSchemaShape::Docblock ? '@OA\Schema docblock' : '#[OA\Schema] attribute',
                $class,
            ),
            fixHint: 'Remove the redundant swagger-php annotation; inference reproduces the same schema.',
            context: [
                Finding::CONTEXT_SOURCE_CLASS => $class,
                self::CONTEXT_SHAPE => $shape->value,
            ],
        );
    }

    /**
     * Whether the authored schema on the class came from `#[OA\*]` attributes or an `@OA` docblock,
     * or null when neither is present (so we never propose an edit we can't locate).
     *
     * @param class-string $class
     *
     * @throws ReflectionException
     */
    private function shapeFor(string $class): ?AuthoredSchemaShape
    {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getAttributes() as $attribute) {
            if (str_starts_with($attribute->getName(), self::ATTRIBUTE_NAMESPACE)) {
                return AuthoredSchemaShape::Attribute;
            }
        }

        $docComment = $reflection->getDocComment();

        if ($docComment !== false && str_contains($docComment, '@OA\\')) {
            return AuthoredSchemaShape::Docblock;
        }

        return null;
    }

    #[Override]
    public function fixer(): Fixer
    {
        return new RedundantOaAnnotationFixer();
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
    public function description(): string
    {
        return 'A hand-authored #[OA\Schema] / @OA\Schema annotation the generator already reproduces via inference.';
    }
}
