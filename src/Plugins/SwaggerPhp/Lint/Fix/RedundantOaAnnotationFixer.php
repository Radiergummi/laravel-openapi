<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;

use function array_values;
use function is_string;

/**
 * Removes a redundant swagger-php annotation, whichever shape it took and wherever it sits.
 *
 * The finding's source member ({@see Finding::CONTEXT_SOURCE_MEMBER}) picks the target: absent for a
 * class-level `#[OA\Schema]` / `@OA\Schema` (the class docblock, plus every `#[OA\*]` group on the
 * class, its properties, and promoted constructor parameters); present for an operation annotation on
 * a controller method (that method's docblock and `#[OA\*]` groups). Attribute edits go through
 * {@see OaAttributeRemover}, docblock edits through {@see DocblockAnnotationRemover}.
 *
 * When the class can't be reflected or the construct can't be located, it yields nothing and the
 * finding is reported as unfixed.
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
                    ? "Remove redundant @OA\\Schema docblock annotation on {$where}"
                    : "Remove redundant @OA operation docblock on {$where}",
                $finding,
                $file,
                $context,
            ),
            AuthoredAnnotationShape::Attribute => $this->removeAttributes(
                $member === null ? $this->classAttributeGroups($classNode) : $this->methodAttributeGroups($methodNode),
                $member === null
                    ? "Remove redundant #[OA\\*] schema attribute on {$where}"
                    : "Remove redundant #[OA\\*] operation attribute on {$where}",
                $finding,
                $file,
                $context,
            ),
        };
    }

    /**
     * @param list<AttributeGroup> $groups
     *
     * @return list<Fix>
     */
    private function removeAttributes(
        array $groups,
        string $description,
        Finding $finding,
        string $file,
        FixContext $context,
    ): array {
        $source = $context->source($file);
        $fixes = [];

        foreach ($groups as $group) {
            foreach ($this->attributeRemover->fixesForGroup($source, $group) as $operation) {
                $fixes[] = new Fix($file, $description, $finding->ruleId, $operation);
            }
        }

        return $fixes;
    }

    /**
     * Every attribute group a class-level `#[OA\*]` schema annotation can sit on: the class itself,
     * its properties, and its promoted constructor parameters.
     *
     * @return list<AttributeGroup>
     */
    private function classAttributeGroups(ClassLike $class): array
    {
        $groups = [];

        foreach ($class->attrGroups as $group) {
            $groups[] = $group;
        }

        foreach ($class->getProperties() as $property) {
            foreach ($property->attrGroups as $group) {
                $groups[] = $group;
            }
        }

        $constructor = $class->getMethod('__construct');

        if ($constructor !== null) {
            foreach ($constructor->params as $param) {
                foreach ($param->attrGroups as $group) {
                    $groups[] = $group;
                }
            }
        }

        return $groups;
    }

    /**
     * The `#[OA\*]` attribute groups on a controller method, or none when the method node is absent.
     *
     * @return list<AttributeGroup>
     */
    private function methodAttributeGroups(?ClassMethod $method): array
    {
        return $method === null ? [] : array_values($method->attrGroups);
    }
}
