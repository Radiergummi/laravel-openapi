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
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredSchemaShape;

use function is_string;

/**
 * Removes a class's redundant swagger-php schema annotation, whichever shape it took.
 *
 * For the **attribute** shape it deletes the class-level `#[OA\Schema]` group and every
 * `#[OA\*]` group on the class's properties (and promoted constructor parameters), delegating the
 * per-group edits to {@see OaAttributeRemover}. For the **docblock** shape it delegates to
 * {@see DocblockAnnotationRemover}.
 *
 * When reflection and the parsed source disagree, or the offending construct can't be located, the
 * fixer yields nothing and the finding is reported as unfixed.
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
        $shapeValue = $finding->context[OaRedundantWithInference::CONTEXT_SHAPE] ?? null;

        if (!is_string($class) || !is_string($shapeValue)) {
            return [];
        }

        $shape = AuthoredSchemaShape::tryFrom($shapeValue);
        $file = $this->fileFor($class);

        if ($shape === null || $file === null) {
            return [];
        }

        return match ($shape) {
            AuthoredSchemaShape::Docblock => $this->docblockRemover->remove($finding, $class, $file, $context),
            AuthoredSchemaShape::Attribute => $this->removeAttributes($finding, $class, $file, $context),
        };
    }

    /**
     * @return list<Fix>
     */
    private function removeAttributes(Finding $finding, string $class, string $file, FixContext $context): array
    {
        $classNode = $context->classNode($file, $class);

        if ($classNode === null) {
            return [];
        }

        $source = $context->source($file);
        $fixes = [];

        foreach ($this->attributeGroups($classNode) as $group) {
            foreach ($this->attributeRemover->fixesForGroup($source, $group) as $operation) {
                $fixes[] = new Fix(
                    file: $file,
                    description: "Remove redundant #[OA\\*] schema attribute on {$class}",
                    ruleId: $finding->ruleId,
                    operation: $operation,
                );
            }
        }

        return $fixes;
    }

    /**
     * Every attribute group declared on the class itself, on its properties, and on its promoted
     * constructor parameters — the places an `#[OA\*]` schema annotation can sit.
     *
     * @return list<AttributeGroup>
     */
    private function attributeGroups(ClassLike $class): array
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

        $constructor = $this->constructor($class);

        if ($constructor !== null) {
            foreach ($constructor->params as $param) {
                foreach ($param->attrGroups as $group) {
                    $groups[] = $group;
                }
            }
        }

        return $groups;
    }

    private function constructor(ClassLike $class): ?ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if ($method->name->toLowerString() === '__construct') {
                return $method;
            }
        }

        return null;
    }
}
