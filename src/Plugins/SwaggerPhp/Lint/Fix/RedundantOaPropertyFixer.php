<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use Override;
use PhpParser\Node\Stmt\ClassLike;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetKind;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;

use function is_string;

/**
 * Removes a single redundant member `#[OA\Property]` / `@OA\Property` from a Spatie Data class,
 * addressing it by the member node (a declared property or a promoted constructor parameter). The
 * member carries only its own `OA\Property`, so removing every `#[OA\*]` on the node, or the `@OA`
 * block in the member's docblock, isolates exactly that one annotation and leaves siblings
 * byte-identical.
 *
 * Yields nothing when the target node cannot be located or the finding context is incomplete.
 *
 * @internal
 */
final readonly class RedundantOaPropertyFixer implements Fixer
{
    use AddressesPropertyNodes;
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

        if (!is_string($class) || !is_string($member) || !is_string($shapeValue)) {
            return [];
        }

        $shape = AuthoredAnnotationShape::tryFrom($shapeValue);
        $file = $this->fileFor($class);
        $classNode = $file === null ? null : $context->classNode($file, $class);

        if ($shape === null || $file === null || $classNode === null) {
            return [];
        }

        $selector = new TargetSelector($class, TargetKind::Property, $member);
        $description = "Remove redundant member #[OA\\Property] / @OA\\Property on {$class}::{$member}";

        return $shape === AuthoredAnnotationShape::Docblock
            ? $this->docblockRemoval($selector, $member, $classNode, $description, $finding, $file, $context)
            : $this->attributeRemoval($selector, $member, $classNode, $description, $finding, $file);
    }

    /**
     * @return list<Fix>
     */
    private function attributeRemoval(
        TargetSelector $selector,
        string $member,
        ClassLike $classNode,
        string $description,
        Finding $finding,
        string $file,
    ): array {
        $operation = $this->attributeRemover->operationFor($selector, $this->propertyAttributeGroups($classNode, $member));

        return $operation === null ? [] : [new Fix($file, $description, $finding->ruleId, $operation)];
    }

    /**
     * @return list<Fix>
     */
    private function docblockRemoval(
        TargetSelector $selector,
        string $member,
        ClassLike $classNode,
        string $description,
        Finding $finding,
        string $file,
        FixContext $context,
    ): array {
        return $this->docblockRemover->removeBlocks(
            $this->propertyDocComment($classNode, $member),
            $selector,
            $description,
            $finding,
            $file,
            $context,
        );
    }
}
