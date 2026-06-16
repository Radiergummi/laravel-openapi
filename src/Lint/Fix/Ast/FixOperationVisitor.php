<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeVisitorAbstract;

use function array_values;
use function krsort;
use function rsort;
use function strcasecmp;

/**
 * Per-Fix visitor that locates the member named by an {@see AstOperation}'s {@see TargetSelector}
 * in the (cloned) tree and applies the operation. `$applied` reports whether the target was found
 * and mutated, so the applicator can keep an unmatched fix out of the "applied" set.
 *
 * A {@see \PhpParser\NodeVisitor\NameResolver} run with `replaceNodes:false` must have populated
 * each class node's `namespacedName` before this visitor runs.
 *
 * @internal
 */
final class FixOperationVisitor extends NodeVisitorAbstract
{
    public private(set) bool $applied = false;

    public function __construct(
        private readonly AstOperation $operation,
    ) {}

    public function enterNode(Node $node): ?Node
    {
        if (! $node instanceof ClassLike) {
            return null;
        }

        if ($node->namespacedName?->toString() !== $this->operation->target->className) {
            return null;
        }

        $member = $this->locateMember($node);

        if ($member === null) {
            return null;
        }

        if ($this->operation instanceof RemoveAttribute) {
            $this->removeAttributes($member, $this->operation);
        }

        return null;
    }

    private function locateMember(ClassLike $class): ClassMethod|Property|Param|null
    {
        $target = $this->operation->target;

        if ($target->kind === TargetKind::Method) {
            return $this->findMethod($class, $target->memberName);
        }

        return $this->findProperty($class, $target->memberName);
    }

    private function findMethod(ClassLike $class, string $member): ?ClassMethod
    {
        return array_find(
            $class->getMethods(),
            static fn(ClassMethod $method): bool => strcasecmp($method->name->toString(), $member) === 0,
        );
    }

    /**
     * The node carrying a property's attributes: either a {@see Property} statement or, for a
     * promoted constructor parameter (the Spatie Data idiom), the matching {@see Param}.
     */
    private function findProperty(ClassLike $class, string $member): Property|Param|null
    {
        foreach ($class->getProperties() as $property) {
            if (array_any(
                $property->props,
                static fn(PropertyItem $prop): bool => $prop->name->toString() === $member,
            )) {
                return $property;
            }
        }

        $constructor = $this->findMethod($class, '__construct');

        if ($constructor === null) {
            return null;
        }

        return array_find(
            $constructor->params,
            static fn(Param $param): bool
                => $param->var instanceof Node\Expr\Variable && $param->var->name === $member,
        );
    }

    private function removeAttributes(ClassMethod|Property|Param $member, RemoveAttribute $operation): void
    {
        // Map each flat, source-order attribute position to its owning group and within-group index,
        // mirroring how the fixer enumerated the member's attributes when it selected the targets.
        $flat = [];

        foreach ($member->attrGroups as $groupIndex => $group) {
            foreach ($group->attrs as $attrIndex => $_) {
                $flat[] = [$groupIndex, $attrIndex];
            }
        }

        // Remove within-group attributes from the highest index down so earlier splices don't shift
        // the positions still to be removed.
        $perGroup = [];

        foreach ($operation->attributeIndices as $flatIndex) {
            if (! isset($flat[$flatIndex])) {
                return;
            }

            [$groupIndex, $attrIndex] = $flat[$flatIndex];
            $perGroup[$groupIndex][] = $attrIndex;
        }

        // Empty the affected groups from the highest group index down for the same reason.
        krsort($perGroup);

        foreach ($perGroup as $groupIndex => $attrIndices) {
            $group = $member->attrGroups[$groupIndex];

            rsort($attrIndices);

            foreach ($attrIndices as $attrIndex) {
                unset($group->attrs[$attrIndex]);
            }

            $group->attrs = array_values($group->attrs);

            if ($group->attrs === []) {
                unset($member->attrGroups[$groupIndex]);
            }
        }

        $member->attrGroups = array_values($member->attrGroups);
        $this->applied = true;
    }
}
