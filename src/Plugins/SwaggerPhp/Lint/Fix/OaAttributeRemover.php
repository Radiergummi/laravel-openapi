<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use Radiergummi\OpenApi\Lint\Fix\Ast\RemoveAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;

use function str_starts_with;

/**
 * Builds an AST-mutation operation that strips every `#[OA\*]` attribute from a node's attribute
 * groups, addressing them by their position in the node's flat, source-order attribute list. The
 * applicator splices those nodes out of the cloned tree (dropping any group it empties) and reprints
 * with the format-preserving printer, leaving surviving non-OA attributes byte-identical.
 *
 * @internal
 */
final readonly class OaAttributeRemover
{
    /**
     * The removal operation for a node, or null when it carries no `#[OA\*]` attribute.
     *
     * @param array<AttributeGroup> $attributeGroups The node's attribute groups, in source order.
     */
    public function operationFor(TargetSelector $target, array $attributeGroups): ?RemoveAttribute
    {
        $indices = [];
        $position = 0;

        foreach ($attributeGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->isOaAttribute($attribute)) {
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
}
