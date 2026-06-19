<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use PhpParser\Comment\Doc;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Locates the attribute groups and docblock of a Spatie Data member, whether it is declared as a
 * property or as a promoted constructor parameter. Shared by the per-member migration fixers, whose
 * `#[OA\Property]` / `@OA\Property` removals address a single member node.
 *
 * @internal
 */
trait AddressesPropertyNodes
{
    /**
     * The attribute groups carrying a member's `#[OA\*]`: either a declared property or a promoted
     * constructor parameter.
     *
     * @return array<AttributeGroup>
     */
    private function propertyAttributeGroups(ClassLike $classNode, string $member): array
    {
        foreach ($classNode->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === $member) {
                    return $property->attrGroups;
                }
            }
        }

        $parameter = $this->promotedParameter($classNode, $member);

        return $parameter === null ? [] : $parameter->attrGroups;
    }

    private function propertyDocComment(ClassLike $classNode, string $member): ?Doc
    {
        foreach ($classNode->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === $member) {
                    return $property->getDocComment();
                }
            }
        }

        return $this->promotedParameter($classNode, $member)?->getDocComment();
    }

    private function promotedParameter(ClassLike $classNode, string $member): ?Param
    {
        $constructor = $classNode->getMethod('__construct');

        if (!$constructor instanceof ClassMethod) {
            return null;
        }

        foreach ($constructor->params as $parameter) {
            if ($parameter->var instanceof Variable && $parameter->var->name === $member) {
                return $parameter;
            }
        }

        return null;
    }
}
