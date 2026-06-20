<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint;

use OpenApi\Annotations as OA;
use Override;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Attributes\ResponseField;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\ComponentSchemaRule;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\OaReplaceableByAttributeFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\DetectsPropertyShape;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\OaAttributeArgumentMapper;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaEquivalence;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use ReflectionException;
use ReflectionMethod;
use Throwable;

use function class_exists;
use function is_string;
use function is_subclass_of;
use function Radiergummi\OpenApi\copy_schema_fields;
use function sprintf;
use function strrpos;
use function substr;

/**
 * Flags a hand-authored `OA\Property` / query `OA\Parameter` that one of the package's authoring
 * attributes (`#[ResponseField]` / `#[QueryParam]`) expresses more concisely, and offers a fixer
 * that rewrites it.
 *
 * The replacement half of the migration story: where {@see OaRedundantWithInference} *removes* a
 * swagger-php annotation inference already reproduces, this *converts* one into a first-class
 * attribute. Phase 1 covers only annotations whose every carried key is a scalar attribute argument
 * ({@see OaAttributeArgumentMapper}); annotations carrying `enum`, array `example`, vendor extensions
 * (`x`), union `type`, or a nested shape are logged and left in place, never partially rewritten.
 *
 * Host-attribute selection is by the class the property lives on: only a Spatie Data class is
 * covered in Phase 1, mapping to `#[ResponseField]`; any other host is logged and skipped (the
 * attribute cannot be picked soundly). API-Resource keys (`#[ResourceField]`) and request DTOs
 * (`#[RequestField]`) are deferred to a follow-up, since their `OA\Property` lives inside the
 * class-level `#[OA\Schema]` that member-level removal cannot address.
 *
 * @internal
 */
final class OaReplaceableByAttribute implements Rule, ComponentSchemaRule, OperationRule, FixableRule
{
    use DetectsPropertyShape;
    public string $id = 'migration.oa-replaceable-by-attribute';
    public Severity $severity = Severity::Improvable;
    public string $description = 'A hand-authored OA\Property / query OA\Parameter an authoring attribute expresses more concisely.';

    /** Finding-context key carrying the target attribute class-string. */
    public const string CONTEXT_TARGET_ATTRIBUTE = 'targetAttribute';

    /** Finding-context key carrying the scalar argument map (so the fixer need not recompute it). */
    public const string CONTEXT_ATTRIBUTE_ARGUMENTS = 'attributeArguments';

    public function __construct(
        private readonly AuthoredAnnotationScanner $scanner,
        private readonly SchemaEquivalence $equivalence,
        private readonly LoggerInterface $logger,
        private OaAttributeArgumentMapper $mapper = new OaAttributeArgumentMapper(),
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

        if ($class === null || !class_exists($class)) {
            return;
        }

        $attribute = $this->fieldAttributeFor($class);

        if ($attribute === null) {
            return;
        }

        foreach ($this->scanner->propertiesForClass($class) as $propertyName => $property) {
            $finding = $this->evaluateProperty($class, $propertyName, $property, $attribute);

            if ($finding !== null) {
                yield $finding;
            }
        }
    }

    /**
     * @return iterable<Finding>
     *
     * @throws ReflectionException
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $controller = $operation->descriptor?->controller?->getName();
        $method = $operation->descriptor?->method?->getName();

        if ($controller === null || $method === null || !class_exists($controller)) {
            return;
        }

        foreach ($this->scanner->queryParametersForMethod($controller, $method) as $parameter) {
            $finding = $this->evaluateQueryParameter($controller, $method, $parameter);

            if ($finding !== null) {
                yield $finding;
            }
        }
    }

    /**
     * @param class-string                 $class
     * @param class-string<FieldAttribute> $attribute
     *
     * @throws ReflectionException
     */
    private function evaluateProperty(
        string $class,
        string $propertyName,
        OA\Property $property,
        string $attribute,
    ): ?Finding {
        $arguments = $this->mapper->mapProperty($property);

        if ($arguments === null) {
            $this->logSkip($class, $propertyName, 'carries a key no scalar attribute argument expresses');

            return null;
        }

        $candidate = $this->buildAttribute($attribute, $arguments);

        if ($candidate === null || !$this->reproduces($candidate->descriptor()->toSchema(), $property)) {
            $this->logSkip($class, $propertyName, 'the attribute would not reproduce the authored schema');

            return null;
        }

        $shape = $this->propertyShape($class, $propertyName);

        if ($shape === null) {
            // The harvester indexed an OA\Property for the class but the member carries no
            // member-level OA attribute or @OA docblock (e.g. it sits on the class-level schema).
            $this->logSkip($class, $propertyName, 'no member-level OA annotation found to rewrite');

            return null;
        }

        return $this->finding(
            sprintf(
                'The %s on %s::$%s can be rewritten as #[%s].',
                $this->shapeLabel($shape),
                $class,
                $propertyName,
                $this->shortName($attribute),
            ),
            $class,
            $propertyName,
            $shape,
            $attribute,
            $arguments,
        );
    }

    /**
     * @param class-string $class
     *
     * @throws ReflectionException
     */
    private function evaluateQueryParameter(string $class, string $method, OA\Parameter $parameter): ?Finding
    {
        $arguments = $this->mapper->mapQueryParameter($parameter);
        $name = $arguments['name'] ?? null;

        if ($arguments === null || !is_string($name)) {
            $this->logSkip($class, $method, 'query parameter carries a key no scalar attribute argument expresses');

            return null;
        }

        $candidate = $this->buildAttribute(QueryParam::class, $arguments);

        if ($candidate === null) {
            $this->logSkip($class, $method, 'the #[QueryParam] attribute would not accept the mapped arguments');

            return null;
        }

        $shape = AuthoredAnnotationShape::detect(new ReflectionMethod($class, $method));

        // A method docblock may carry sibling `@OA` blocks (`@OA\Get`, other parameters) the
        // all-or-nothing docblock remover cannot strip per-parameter, so the docblock query shape is
        // deferred; only the attribute shape removes a single `#[OA\Parameter]` soundly.
        if ($shape !== AuthoredAnnotationShape::Attribute) {
            if ($shape === AuthoredAnnotationShape::Docblock) {
                $this->logSkip($class, $method, 'query @OA\Parameter docblock shape is deferred to a follow-up');
            }

            return null;
        }

        return $this->finding(
            sprintf('The query %s on %s::%s() can be rewritten as #[QueryParam].', $this->shapeLabel($shape), $class, $method),
            $class,
            $method,
            $shape,
            QueryParam::class,
            $arguments,
        );
    }

    /**
     * The field attribute appropriate to a property-bearing class, or null when the class is not a
     * Spatie Data class (no sound mapping).
     *
     * Phase 1 covers Data-class properties only: their `OA\Property` is a member-level attribute or
     * property docblock the fixer can rewrite at single-property granularity. API-Resource keys live
     * inside the class-level `#[OA\Schema]`, which member-level removal cannot address, so
     * `#[ResourceField]` (and `#[RequestField]`) selection is deferred.
     *
     * @return null|class-string<FieldAttribute>
     */
    private function fieldAttributeFor(string $class): ?string
    {
        if (class_exists('Spatie\\LaravelData\\Data') && is_subclass_of($class, 'Spatie\\LaravelData\\Data')) {
            return ResponseField::class;
        }

        return null;
    }

    /**
     * Instantiates the candidate attribute from the scalar argument map, returning null when a named
     * argument the attribute does not declare (e.g. `deprecated` on `#[ResponseField]`) makes the
     * mapping unsound.
     *
     * @param class-string<FieldAttribute>         $attribute
     * @param array<string, bool|float|int|string> $arguments
     */
    private function buildAttribute(string $attribute, array $arguments): ?FieldAttribute
    {
        try {
            return new $attribute(...$arguments);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the candidate attribute's schema reproduces exactly the authored property's schema
     * content (member-name field aside), checked by bidirectional structural containment.
     */
    private function reproduces(OA\Schema $candidate, OA\Property $authored): bool
    {
        $authoredSchema = copy_schema_fields($authored, new OA\Schema([]));

        return $this->equivalence->subsumes($candidate, $authoredSchema)
            && $this->equivalence->subsumes($authoredSchema, $candidate);
    }

    /**
     * @param class-string<FieldAttribute>         $attribute
     * @param array<string, bool|float|int|string> $arguments
     */
    private function finding(
        string $message,
        string $class,
        string $member,
        AuthoredAnnotationShape $shape,
        string $attribute,
        array $arguments,
    ): Finding {
        return new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: $message,
            fixHint: sprintf('Rewrite the swagger-php annotation as #[%s].', $this->shortName($attribute)),
            context: [
                Finding::CONTEXT_SOURCE_CLASS => $class,
                Finding::CONTEXT_SOURCE_MEMBER => $member,
                AuthoredAnnotationShape::FINDING_CONTEXT_KEY => $shape->value,
                self::CONTEXT_TARGET_ATTRIBUTE => $attribute,
                self::CONTEXT_ATTRIBUTE_ARGUMENTS => $arguments,
            ],
        );
    }

    private function logSkip(string $class, string $member, string $reason): void
    {
        $this->logger->info(sprintf(
            'migration.oa-replaceable-by-attribute: left %s::%s in place (%s).',
            $class,
            $member,
            $reason,
        ));
    }

    private function shapeLabel(AuthoredAnnotationShape $shape): string
    {
        return $shape === AuthoredAnnotationShape::Docblock ? '@OA annotation' : '#[OA\*] attribute';
    }

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }



    #[Override]
    public function fixer(): Fixer
    {
        return new OaReplaceableByAttributeFixer();
    }

}
