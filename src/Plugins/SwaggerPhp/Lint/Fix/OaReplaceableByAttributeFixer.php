<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use Override;
use PhpParser\Comment\Doc;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\Ast\RewriteToAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\SetDocComment;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetKind;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaReplaceableByAttribute;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;

use function class_exists;
use function is_array;
use function is_string;
use function ltrim;
use function strrpos;
use function substr;

/**
 * Rewrites a replaceable swagger-php annotation into the equivalent authoring attribute. Per finding
 * it emits two fixes on the same file: one {@see AddAttribute} placing the new attribute on the
 * target member, and one removal of the source annotation at *single-member* granularity (the OA
 * attribute on that property/method, or the `@OA` block in that member's docblock).
 *
 * Yields nothing when the target node cannot be located or the finding context is incomplete.
 *
 * @internal
 */
final readonly class OaReplaceableByAttributeFixer implements Fixer
{
    use ResolvesDeclaringFile;

    public function __construct(
        private DocblockAnnotationRemover $docblockRemover = new DocblockAnnotationRemover(),
        private OaAttributeRemover $attributeRemover = new OaAttributeRemover(),
    ) {}

    /**
     * @return iterable<Fix>
     */
    #[Override]
    public function fix(Finding $finding, FixContext $context): iterable
    {
        $class = $finding->context[Finding::CONTEXT_SOURCE_CLASS] ?? null;
        $member = $finding->context[Finding::CONTEXT_SOURCE_MEMBER] ?? null;
        $shapeValue = $finding->context[AuthoredAnnotationShape::FINDING_CONTEXT_KEY] ?? null;
        $attribute = $finding->context[OaReplaceableByAttribute::CONTEXT_TARGET_ATTRIBUTE] ?? null;
        $arguments = $finding->context[OaReplaceableByAttribute::CONTEXT_ATTRIBUTE_ARGUMENTS] ?? null;

        if (
            !is_string($class) || !is_string($member) || !is_string($shapeValue)
            || !is_string($attribute) || !is_array($arguments)
        ) {
            return [];
        }

        $shape = AuthoredAnnotationShape::tryFrom($shapeValue);
        $file = $this->fileFor($class);
        $classNode = $file === null ? null : $context->classNode($file, $class);

        if ($shape === null || $file === null || $classNode === null) {
            return [];
        }

        if (!class_exists($attribute)) {
            return [];
        }

        // A query parameter is addressed by its controller method; a field by its property/param.
        $isQueryParameter = $attribute === QueryParam::class;
        $kind = $isQueryParameter ? TargetKind::Method : TargetKind::Property;
        $selector = new TargetSelector($class, $kind, $member);

        /** @var array<string, bool|float|int|string> $arguments */
        $operation = $shape === AuthoredAnnotationShape::Docblock
            ? $this->docblockRewrite($selector, $member, $classNode, $isQueryParameter, $attribute, $arguments, $finding, $file, $context)
            : $this->attributeRewrite($selector, $member, $classNode, $isQueryParameter, $attribute, $arguments);

        if ($operation === null) {
            return [];
        }

        return [new Fix(
            $file,
            "Rewrite the swagger-php annotation on {$class}::{$member} as #[{$this->shortName($attribute)}]",
            $finding->ruleId,
            $operation,
        )];
    }

    /**
     * The atomic rewrite for an attribute-shape annotation: remove the member's `#[OA\*]` attributes
     * by flat index and prepend the new attribute. Null when the member carries no `#[OA\*]`.
     *
     * @param class-string                         $attribute
     * @param array<string, bool|float|int|string> $arguments
     */
    private function attributeRewrite(
        TargetSelector $selector,
        string $member,
        ClassLike $classNode,
        bool $isQueryParameter,
        string $attribute,
        array $arguments,
    ): ?RewriteToAttribute {
        // A field property carries exactly its own `#[OA\Property]`, so removing every OA attribute on
        // it is correct. A controller method carries other OA attributes (`#[OA\Get]`, sibling
        // parameters), so only the matching `#[OA\Parameter]` may be removed.
        if ($isQueryParameter) {
            $name = $arguments['name'] ?? null;
            $method = $classNode->getMethod($member);
            $indices = $method === null || !is_string($name)
                ? []
                : $this->queryParameterIndices($method->attrGroups, $name);

            return $indices === []
                ? null
                : new RewriteToAttribute($selector, $attribute, $arguments, attributeIndices: $indices);
        }

        $removal = $this->attributeRemover->operationFor($selector, $this->propertyAttributeGroups($classNode, $member));

        if ($removal === null) {
            return null;
        }

        return new RewriteToAttribute($selector, $attribute, $arguments, attributeIndices: $removal->attributeIndices);
    }

    /**
     * Flat indices of the `#[OA\Parameter]` attributes whose `name:` argument matches `$name`, so the
     * rewrite removes only the parameter it replaces and leaves `#[OA\Get]` and siblings intact.
     *
     * @param array<AttributeGroup> $groups
     *
     * @return list<int>
     */
    private function queryParameterIndices(array $groups, string $name): array
    {
        $indices = [];
        $position = 0;

        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->isParameterNamed($attribute, $name)) {
                    $indices[] = $position;
                }

                $position++;
            }
        }

        return $indices;
    }

    private function isParameterNamed(Attribute $attribute, string $name): bool
    {
        $resolved = $attribute->name->getAttribute('resolvedName');
        $className = $resolved instanceof Name ? $resolved->toString() : $attribute->name->toString();

        if (ltrim($className, '\\') !== 'OpenApi\\Attributes\\Parameter') {
            return false;
        }

        foreach ($attribute->args as $argument) {
            if (
                $argument->name?->toString() === 'name'
                && $argument->value instanceof String_
                && $argument->value->value === $name
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The atomic rewrite for a docblock-shape annotation: strip the member's `@OA` block (computed
     * with source access) and prepend the new attribute. Null when no `@OA` block is found.
     *
     * @param class-string                         $attribute
     * @param array<string, bool|float|int|string> $arguments
     */
    private function docblockRewrite(
        TargetSelector $selector,
        string $member,
        ClassLike $classNode,
        bool $isQueryParameter,
        string $attribute,
        array $arguments,
        Finding $finding,
        string $file,
        FixContext $context,
    ): ?RewriteToAttribute {
        $doc = $isQueryParameter
            ? $classNode->getMethod($member)?->getDocComment()
            : $this->propertyDocComment($classNode, $member);

        $removal = $this->docblockRemover->removeBlocks($doc, $selector, 'rewrite', $finding, $file, $context);
        $operation = $removal[0]->operation ?? null;

        if (!$operation instanceof SetDocComment) {
            return null;
        }

        return new RewriteToAttribute($selector, $attribute, $arguments, docComment: $operation->text);
    }

    /**
     * The attribute groups carrying a property's `#[OA\*]`: either a declared property or a promoted
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

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
