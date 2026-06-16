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
 * Flags authoring attributes marked deprecated (`#[Deprecated]` or `@deprecated`), enabling smooth
 * migration when an attribute is renamed or replaced.
 */
final class DeprecatedAttribute implements Rule, OperationRuleVisitor, Resettable
{
    private readonly string $attributeNamespace;

    /** @var array<class-string, ReflectionClass<object>> */
    private array $reflectionCache = [];

    public function __construct(?string $attributeNamespace = null)
    {
        // Derived from an actual attribute class so a package-namespace rename stays correct.
        $this->attributeNamespace = $attributeNamespace
            ?? (new ReflectionClass(FieldAttribute::class)->getNamespaceName() . '\\');
    }

    /** Clears the reflection cache between walks. */
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
     * @param ReflectionAttribute<object>[] $attributes
     * @param string                        $location   Human-readable location for the message
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

    /** @param ReflectionClass<object> $reflection */
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
        // Convention issue: spec is still valid, but author uses a superseded attribute.
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'A deprecated authoring attribute (#[Deprecated] or @deprecated) is still used on a controller.';
    }
}
