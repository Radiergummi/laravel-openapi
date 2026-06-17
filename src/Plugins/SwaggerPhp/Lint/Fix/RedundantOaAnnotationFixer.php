<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use Override;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\Ast\RemoveAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetKind;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;

use function is_string;

/**
 * Removes a redundant swagger-php annotation (docblock or attribute), targeting the class or
 * method identified by the finding's context. Yields nothing when the target cannot be located.
 *
 * @internal
 */
final readonly class RedundantOaAnnotationFixer implements Fixer
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

        if (!is_string($class) || !is_string($shapeValue) || ($member !== null && !is_string($member))) {
            return [];
        }

        $shape = AuthoredAnnotationShape::tryFrom($shapeValue);
        $file = $this->fileFor($class);
        $classNode = $file === null ? null : $context->classNode($file, $class);

        if ($shape === null || $file === null || $classNode === null) {
            return [];
        }

        $methodNode = $member !== null ? $classNode->getMethod($member) : null;
        $where = $member === null ? $class : "{$class}::{$member}";

        return match ($shape) {
            AuthoredAnnotationShape::Docblock => $this->docblockRemover->removeBlocks(
                $member === null ? $classNode->getDocComment() : $methodNode?->getDocComment(),
                $member === null
                    ? new TargetSelector($class, TargetKind::ClassNode)
                    : new TargetSelector($class, TargetKind::Method, $member),
                $member === null
                    ? "Remove redundant @OA\\Schema docblock annotation on {$where}"
                    : "Remove redundant @OA operation docblock on {$where}",
                $finding,
                $file,
                $context,
            ),
            AuthoredAnnotationShape::Attribute => $this->removeAttributes(
                $member === null
                    ? $this->classAttributeTargets($class, $classNode)
                    : $this->methodAttributeTargets($class, $member, $methodNode),
                $member === null
                    ? "Remove redundant #[OA\\*] schema attribute on {$where}"
                    : "Remove redundant #[OA\\*] operation attribute on {$where}",
                $finding,
                $file,
            ),
        };
    }

    /**
     * One {@see Fix} per addressed node that carries an `#[OA\*]` attribute.
     *
     * @param list<array{TargetSelector, array<AttributeGroup>}> $targets
     *
     * @return list<Fix>
     */
    private function removeAttributes(
        array $targets,
        string $description,
        Finding $finding,
        string $file,
    ): array {
        $fixes = [];

        foreach ($targets as [$selector, $groups]) {
            $operation = $this->attributeRemover->operationFor($selector, $groups);

            if ($operation instanceof RemoveAttribute) {
                $fixes[] = new Fix($file, $description, $finding->ruleId, $operation);
            }
        }

        return $fixes;
    }

    /**
     * The class itself, each declared property, and each promoted constructor parameter, paired with
     * its structural address. The remover skips the ones without an `#[OA\*]` attribute.
     *
     * @return list<array{TargetSelector, array<AttributeGroup>}>
     */
    private function classAttributeTargets(string $class, ClassLike $classNode): array
    {
        $targets = [
            [new TargetSelector($class, TargetKind::ClassNode), $classNode->attrGroups],
        ];

        foreach ($classNode->getProperties() as $property) {
            $name = $property->props[0]->name->toString();
            $targets[] = [new TargetSelector($class, TargetKind::Property, $name), $property->attrGroups];
        }

        $constructor = $classNode->getMethod('__construct');

        if ($constructor !== null) {
            foreach ($constructor->params as $param) {
                if ($param->var instanceof Variable && is_string($param->var->name)) {
                    $targets[] = [
                        new TargetSelector($class, TargetKind::Property, $param->var->name),
                        $param->attrGroups,
                    ];
                }
            }
        }

        return $targets;
    }

    /**
     * @return list<array{TargetSelector, array<AttributeGroup>}>
     */
    private function methodAttributeTargets(string $class, string $member, ?ClassMethod $method): array
    {
        if ($method === null) {
            return [];
        }

        return [[new TargetSelector($class, TargetKind::Method, $member), $method->attrGroups]];
    }
}
