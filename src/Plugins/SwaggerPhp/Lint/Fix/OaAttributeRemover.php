<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Scalar\String_;
use Radiergummi\OpenApi\Lint\Fix\Ast\RemoveAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;

use function in_array;
use function str_starts_with;

/**
 * Builds an AST-mutation operation that strips `#[OA\*]` attributes from a node's attribute groups,
 * addressing them by their position in the node's flat, source-order attribute list. The applicator
 * splices those nodes out of the cloned tree (dropping any group it empties) and reprints with the
 * format-preserving printer, leaving surviving non-OA attributes byte-identical.
 *
 * Without a component name every `#[OA\*]` attribute on the node is removed (the schema/operation
 * rules' behavior). With one, removal is narrowed to the single reusable `#[OA\Response]` /
 * `#[OA\Parameter]` whose `response:`/`parameter:` argument names that component, so a class stacking
 * several component definitions keeps the load-bearing siblings.
 *
 * @internal
 */
final readonly class OaAttributeRemover
{
    /**
     * The removal operation for a node, or null when it carries no matching `#[OA\*]` attribute.
     *
     * @param array<AttributeGroup> $attributeGroups The node's attribute groups, in source order.
     */
    public function operationFor(
        TargetSelector $target,
        array $attributeGroups,
        ?string $componentName = null,
    ): ?RemoveAttribute {
        $indices = [];
        $position = 0;

        foreach ($attributeGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->isOaAttribute($attribute) && $this->matchesComponent($attribute, $componentName)) {
                    $indices[] = $position;
                }

                $position++;
            }
        }

        if ($indices === []) {
            return null;
        }

        return new RemoveAttribute($target, $indices);
    }

    private function isOaAttribute(Attribute $attribute): bool
    {
        $resolved = $attribute->name->getAttribute('resolvedName');
        $name = $resolved instanceof Node\Name ? $resolved->toString() : $attribute->name->toString();

        return str_starts_with($name, AuthoredAnnotationShape::ATTRIBUTE_NAMESPACE);
    }

    /**
     * Whether the attribute defines the named reusable component. A null name matches any attribute
     * (whole-node removal); otherwise the attribute's `response:`/`parameter:` argument must equal it.
     */
    private function matchesComponent(Attribute $attribute, ?string $componentName): bool
    {
        if ($componentName === null) {
            return true;
        }

        foreach ($attribute->args as $argument) {
            $name = $argument->name?->toString();

            if (in_array($name, ['response', 'parameter'], true) && $argument->value instanceof String_) {
                return $argument->value->value === $componentName;
            }
        }

        return false;
    }
}
