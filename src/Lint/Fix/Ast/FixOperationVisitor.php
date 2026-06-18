<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeVisitorAbstract;

use function array_any;
use function array_keys;
use function array_map;
use function array_unshift;
use function array_values;
use function is_float;
use function is_int;
use function krsort;
use function ltrim;
use function rsort;
use function strcasecmp;

/**
 * Per-Fix visitor that locates the node addressed by an {@see AstOperation}'s {@see TargetSelector}
 * (the class itself, or a named method/property/promoted parameter) in the (cloned) tree and
 * applies the operation. `$applied` reports whether the target was found and mutated, so the
 * applicator can keep an unmatched fix out of the "applied" set.
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
        } elseif ($this->operation instanceof SetDocComment) {
            $this->setDocComment($member, $this->operation);
        } elseif ($this->operation instanceof SetAttributeArgument) {
            $this->setAttributeArgument($member, $this->operation);
        } elseif ($this->operation instanceof AddAttribute) {
            $this->addAttribute($member, $this->operation);
        } elseif ($this->operation instanceof RewriteToAttribute) {
            $this->rewriteToAttribute($member, $this->operation);
        }

        return null;
    }

    private function locateMember(ClassLike $class): ClassLike|ClassMethod|Property|Param|null
    {
        $target = $this->operation->target;

        return match ($target->kind) {
            TargetKind::ClassNode => $class,
            TargetKind::Method    => $target->memberName === null
                ? null
                : $this->findMethod($class, $target->memberName),
            TargetKind::Property  => $target->memberName === null
                ? null
                : $this->findProperty($class, $target->memberName),
        };
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

    private function removeAttributes(ClassLike|ClassMethod|Property|Param $member, RemoveAttribute $operation): void
    {
        if ($this->removeAttributesByIndex($member, $operation->attributeIndices)) {
            $this->applied = true;
        }
    }

    /**
     * Removes the flat-indexed attributes from a member's groups, dropping any group it empties.
     * Returns whether every index resolved (a stale index aborts without mutating).
     *
     * @param list<int> $attributeIndices
     */
    private function removeAttributesByIndex(ClassLike|ClassMethod|Property|Param $member, array $attributeIndices): bool
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

        foreach ($attributeIndices as $flatIndex) {
            if (! isset($flat[$flatIndex])) {
                return false;
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

        return true;
    }

    private function setDocComment(ClassLike|ClassMethod|Property|Param $member, SetDocComment $operation): void
    {
        $comments = $member->getComments();
        $rebuilt = [];
        $changed = false;

        foreach ($comments as $comment) {
            if (! $comment instanceof Doc) {
                $rebuilt[] = $comment;

                continue;
            }

            $changed = true;

            // null text drops the doc comment entirely (and its physical lines); otherwise rebuild
            // it from the new text, reusing the original positions so the printer reprints in place.
            if ($operation->text !== null) {
                $rebuilt[] = new Doc(
                    $operation->text,
                    $comment->getStartLine(),
                    $comment->getStartFilePos(),
                    $comment->getStartTokenPos(),
                    $comment->getEndLine(),
                    $comment->getEndFilePos(),
                    $comment->getEndTokenPos(),
                );
            }
        }

        if (! $changed) {
            return;
        }

        $member->setAttribute('comments', $rebuilt);
        $this->applied = true;
    }

    /**
     * Rebuilds the member's doc comment from `$text` (null drops it entirely), reusing the original
     * positions so the format-preserving printer reprints in place. Mirrors {@see setDocComment()}.
     */
    private function replaceDocComment(ClassLike|ClassMethod|Property|Param $member, ?string $text): void
    {
        $rebuilt = [];

        foreach ($member->getComments() as $comment) {
            if (! $comment instanceof Doc) {
                $rebuilt[] = $comment;

                continue;
            }

            if ($text !== null) {
                $rebuilt[] = new Doc(
                    $text,
                    $comment->getStartLine(),
                    $comment->getStartFilePos(),
                    $comment->getStartTokenPos(),
                    $comment->getEndLine(),
                    $comment->getEndFilePos(),
                    $comment->getEndTokenPos(),
                );
            }
        }

        $member->setAttribute('comments', $rebuilt);
    }

    private function setAttributeArgument(
        ClassLike|ClassMethod|Property|Param $member,
        SetAttributeArgument $operation,
    ): void {
        $attribute = $this->attributeAt($member, $operation->attributeIndex);

        if ($attribute === null) {
            return;
        }

        // Refuse rather than guess when the attribute carries positional arguments that a named
        // add/remove cannot reason about, so a half-mutated attribute never ships to disk.
        if ($this->positionalCollision($attribute, $operation)) {
            return;
        }

        if ($this->mutateAttributeArguments($attribute, $operation)) {
            $this->applied = true;
        }
    }

    private function attributeAt(ClassLike|ClassMethod|Property|Param $member, int $flatIndex): ?Attribute
    {
        $position = 0;

        foreach ($member->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($position === $flatIndex) {
                    return $attribute;
                }

                $position++;
            }
        }

        return null;
    }

    /**
     * Whether a named add/remove on this attribute is unsafe because the named argument is absent
     * yet positional arguments are present (we cannot know which positional slot it maps to).
     */
    private function positionalCollision(Attribute $attribute, SetAttributeArgument $operation): bool
    {
        $hasNamed = false;
        $hasPositional = false;

        foreach ($attribute->args as $argument) {
            if ($argument->name === null) {
                $hasPositional = true;

                continue;
            }

            if ($argument->name->toString() === $operation->argumentName) {
                $hasNamed = true;
            }
        }

        return !$hasNamed && $hasPositional;
    }

    /**
     * Returns whether the attribute was actually changed. The remove-when-already-absent branch is a
     * legitimate idempotent no-op and returns false so the fix is not counted as applied.
     */
    private function mutateAttributeArguments(Attribute $attribute, SetAttributeArgument $operation): bool
    {
        $hasNamed = array_any(
            $attribute->args,
            static fn(Arg $argument): bool => $argument->name?->toString() === $operation->argumentName,
        );

        if ($operation->remove) {
            // Already absent is a legitimate idempotent no-op; the positional case was refused above.
            if (! $hasNamed) {
                return false;
            }

            $attribute->args = array_values(array_filter(
                $attribute->args,
                static fn(Arg $argument): bool => $argument->name?->toString() !== $operation->argumentName,
            ));

            return true;
        }

        $newArgument = new Arg(
            value: $this->literalToNode($operation->value),
            name: new Identifier($operation->argumentName),
        );

        if (! $hasNamed) {
            $attribute->args[] = $newArgument;

            return true;
        }

        foreach ($attribute->args as $index => $argument) {
            if ($argument->name?->toString() === $operation->argumentName) {
                $attribute->args[$index] = $newArgument;
            }
        }

        return true;
    }

    private function addAttribute(ClassLike|ClassMethod|Property|Param $member, AddAttribute $operation): void
    {
        // The fixer decides add-vs-modify; the op only inserts. Adding a second application of an
        // attribute the member already carries is never what a fixer wants, so refuse it here, which
        // also makes the op idempotent against its own output.
        if ($this->hasAttributeClass($member, $operation->attributeClass)) {
            return;
        }

        $this->prependAttribute($member, $operation->attributeClass, $operation->arguments);
        $this->applied = true;
    }

    /**
     * Removes the source annotation (OA attributes by flat index, or the `@OA` docblock) and
     * prepends the new attribute, both in one visit so no later op resolves a stale flat index.
     */
    private function rewriteToAttribute(ClassLike|ClassMethod|Property|Param $member, RewriteToAttribute $operation): void
    {
        if ($this->hasAttributeClass($member, $operation->attributeClass)) {
            return;
        }

        if ($operation->attributeIndices !== null) {
            $this->removeAttributesByIndex($member, $operation->attributeIndices);
        }

        if ($operation->docComment !== false) {
            $this->replaceDocComment($member, $operation->docComment);
        }

        $this->prependAttribute($member, $operation->attributeClass, $operation->arguments);
        $this->applied = true;
    }

    /**
     * Prepends a new attribute group with the fully-qualified attribute name (so the rewrite stays
     * correct without editing the file's `use` block) and the given named scalar arguments.
     *
     * @param class-string                              $attributeClass
     * @param array<string, null|bool|float|int|string> $arguments
     */
    private function prependAttribute(
        ClassLike|ClassMethod|Property|Param $member,
        string $attributeClass,
        array $arguments,
    ): void {
        $args = array_map(
            fn(string|int|float|bool|null $value, string $name): Arg => new Arg(
                value: $this->literalToNode($value),
                name: new Identifier($name),
            ),
            array_values($arguments),
            array_keys($arguments),
        );

        $group = new AttributeGroup([
            new Attribute(new FullyQualified(ltrim($attributeClass, '\\')), $args),
        ]);

        array_unshift($member->attrGroups, $group);
    }

    /**
     * Whether the member already carries an application of the given attribute class, comparing by
     * the name {@see \PhpParser\NodeVisitor\NameResolver} resolved (it stamps `resolvedName` on
     * statically-resolvable names) so a short, `use`-imported reference matches its FQCN.
     */
    private function hasAttributeClass(ClassLike|ClassMethod|Property|Param $member, string $attributeClass): bool
    {
        $target = ltrim($attributeClass, '\\');

        foreach ($member->attrGroups as $group) {
            $matches = array_any(
                $group->attrs,
                static function (Attribute $attribute) use ($target): bool {
                    $resolved = $attribute->name->getAttribute('resolvedName');
                    $name = $resolved instanceof Name ? $resolved : $attribute->name;

                    return ltrim($name->toString(), '\\') === $target;
                },
            );

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function literalToNode(string|int|float|bool|null $value): Node\Expr
    {
        return match (true) {
            $value === null  => new Node\Expr\ConstFetch(new Name('null')),
            $value === true  => new Node\Expr\ConstFetch(new Name('true')),
            $value === false => new Node\Expr\ConstFetch(new Name('false')),
            is_int($value)   => new Scalar\Int_($value),
            is_float($value) => new Scalar\Float_($value),
            default          => new Scalar\String_($value),
        };
    }
}
