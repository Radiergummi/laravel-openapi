<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Deprecated;
use Override;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Lint\Visitors\Resettable;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;

use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * Flags usage of OpenAPI authoring attributes that have been marked as deprecated via the
 * at-deprecated PHPDoc tag on the attribute class.
 *
 * This rule exists so that future attribute renames or replacements have a smooth migration path:
 * Mark the old attribute class as at-deprecated, and this rule will emit warnings for every
 * remaining usage.
 */
final class DeprecatedAttribute implements Rule, OperationRuleVisitor, Resettable
{
    private readonly string $attributeNamespace;

    /** @var array<class-string, ReflectionClass<object>> */
    private array $reflectionCache = [];

    public function __construct(?string $attributeNamespace = null)
    {
        // Derive the default namespace from an actual Core attribute class so a future
        // package-namespace rename does not require editing this constant.
        $this->attributeNamespace = $attributeNamespace
            ?? (new ReflectionClass(FieldAttribute::class)->getNamespaceName() . '\\');
    }

    /** Clears the per-attribute reflection cache so it cannot grow unbounded across walks. */
    #[Override]
    public function reset(): void
    {
        $this->reflectionCache = [];
    }

    /**
     * @return iterable<Finding>
     *
     * @throws ReflectionException
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook) {
            return;
        }

        if ($operation->descriptor === null || $operation->descriptor->method === null) {
            return;
        }

        $controllerName = $operation->descriptor->controller?->getShortName() ?? '(unknown)';

        yield from $this->checkAttributes(
            $operation->descriptor->method->getAttributes(),
            sprintf('%s::%s()', $controllerName, $operation->descriptor->method->getName()),
        );

        if ($operation->descriptor->controller !== null) {
            yield from $this->checkAttributes(
                $operation->descriptor->controller->getAttributes(),
                sprintf('class %s', $operation->descriptor->controller->getShortName()),
            );
        }
    }

    /**
     * Emits a finding for every deprecated authoring attribute in the list.
     *
     * @param ReflectionAttribute<object>[] $attributes
     * @param string                        $location   Where the attribute sits, for the message
     *                                                  (e.g. `Controller::method()` or `class Controller`)
     *
     * @return iterable<Finding>
     *
     * @throws ReflectionException
     */
    private function checkAttributes(array $attributes, string $location): iterable
    {
        foreach ($attributes as $attribute) {
            $attrClass = $attribute->getName();

            if (!str_starts_with($attrClass, $this->attributeNamespace)) {
                continue;
            }

            if (!array_key_exists($attrClass, $this->reflectionCache)) {
                $this->reflectionCache[$attrClass] = new ReflectionClass($attrClass);
            }

            $attrReflection = $this->reflectionCache[$attrClass];

            if (!$this->isDeprecated($attrReflection)) {
                continue;
            }

            $shortName = $attrReflection->getShortName();

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Attribute #[%s] is deprecated and should be replaced on %s',
                    $shortName,
                    $location,
                ),
                fixHint: sprintf('Replace #[%s] with its recommended successor.', $shortName),
            );
        }
    }

    /**
     * Check whether the given attribute class is marked deprecated.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function isDeprecated(ReflectionClass $reflection): bool
    {
        if ($reflection->getAttributes(Deprecated::class) !== []) {
            return true;
        }

        $docComment = $reflection->getDocComment();

        if ($docComment === false) {
            return false;
        }

        return str_contains($docComment, '@deprecated');
    }

    #[Override]
    public function id(): string
    {
        return 'deprecated.attribute';
    }

    #[Override]
    public function level(): int
    {
        // Using a deprecated authoring attribute is a convention issue, not a
        // spec-validity error — the generated spec is still correct, but the
        // author is leaning on a superseded attribute.
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'A deprecated authoring attribute (#[Deprecated] or @deprecated) is still used on a controller.';
    }
}
